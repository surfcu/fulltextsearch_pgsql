<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Model\ParsedQuery;

/**
 * Turns a user's search string into parameterised tsquery SQL expressions.
 *
 * Follows the Full Text Search framework's query contract (the one Elasticsearch implements
 * and `occ fulltextsearch:test` checks):
 *   word          optional; a document needs at least one optional term unless there are
 *                 required ones. Words match as prefixes ("rapor" finds "raporlar").
 *   +word         required
 *   -word         excluded (exact word, so "-test" does not exclude "testimony")
 *   "two words"   phrase; can be prefixed with + or -
 * Documents containing every term are ranked ahead of partial matches.
 *
 * User text never reaches SQL or the tsquery syntax unescaped: each term is a bound
 * parameter, quoted as a tsquery literal.
 */
class TsQueryBuilder {

	private const MAX_TERMS = 32;
	private const MAX_TERM_LENGTH = 100;

	/**
	 * @return ParsedQuery|null null when there is nothing positive to look for
	 */
	public function build(string $search, string $language): ?ParsedQuery {
		$search = mb_scrub($search, 'UTF-8');
		if ($language === 'turkish') {
			// Same dotted/dotless I mapping as the indexed text (see SchemaService::normalized()).
			$search = strtr($search, ['I' => 'ı', 'İ' => 'i']);
		}
		preg_match_all('/([+-]?)"([^"]*)"?|([+-]?)(\S+)/u', $search, $matches, PREG_SET_ORDER);

		/** @var array{optional: list<array{0: string, 1: list<string>}>, required: list<array{0: string, 1: list<string>}>, excluded: list<array{0: string, 1: list<string>}>} $terms */
		$terms = ['optional' => [], 'required' => [], 'excluded' => []];
		$words = [];
		$count = 0;

		foreach ($matches as $m) {
			if ($count >= self::MAX_TERMS) {
				break;
			}
			$isPhrase = !isset($m[4]) || $m[4] === '';
			$operator = $isPhrase ? $m[1] : $m[3];
			$text = $this->trimTerm($isPhrase ? $m[2] : $m[4]);
			if ($text === '' || (!$isPhrase && $operator === '' && in_array($text, ['OR', 'AND'], true))) {
				// Boolean keywords are not needed: optional terms are already ORed.
				continue;
			}

			$kind = match ($operator) {
				'+' => 'required',
				'-' => 'excluded',
				default => 'optional',
			};
			if ($isPhrase) {
				$term = ['phraseto_tsquery(CAST(? AS regconfig), ?)', [$language, $text]];
			} else {
				$literal = $this->literal($text) . ($kind === 'excluded' ? '' : ':*');
				$term = ['to_tsquery(CAST(? AS regconfig), ?)', [$language, $literal]];
			}
			$terms[$kind][] = $term;
			if ($kind !== 'excluded') {
				$words[] = $text;
			}
			$count++;
		}

		$required = $this->combine($terms['required'], '&&');
		$optional = $this->combine($terms['optional'], '||');
		$excluded = $this->combine($terms['excluded'], '||');

		// A query made only of exclusions would scan the whole table and match nearly everything.
		$base = $required ?? $optional;
		if ($base === null) {
			return null;
		}

		$match = $excluded === null ? $base : $this->join([$base, ['(!! ' . $excluded[0] . ')', $excluded[1]]], '&&');
		$rank = $required !== null && $optional !== null ? $this->join([$required, $optional], '||') : $base;
		$all = $this->combine(array_merge($terms['required'], $terms['optional']), '&&') ?? $base;

		return new ParsedQuery($match[0], $match[1], $rank[0], $rank[1], $all[0], $all[1], implode(' ', $words));
	}

	/**
	 * @param list<array{0: string, 1: list<string>}> $terms
	 * @return array{0: string, 1: list<string>}|null
	 */
	private function combine(array $terms, string $operator): ?array {
		return $terms === [] ? null : $this->join($terms, $operator);
	}

	/**
	 * @param non-empty-list<array{0: string, 1: list<string>}> $terms
	 * @return array{0: string, 1: list<string>}
	 */
	private function join(array $terms, string $operator): array {
		if (count($terms) === 1) {
			return $terms[0];
		}
		$params = [];
		foreach ($terms as $term) {
			array_push($params, ...$term[1]);
		}
		return ['(' . implode(" $operator ", array_column($terms, 0)) . ')', $params];
	}

	/** Quote a word as a tsquery literal: o'brien -> 'o''brien' */
	private function literal(string $word): string {
		return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $word) . "'";
	}

	private function trimTerm(string $term): string {
		// Drop punctuation at the edges ("word," or "(word)"), keep inner characters (e-mail, o'brien).
		$term = trim((string)preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $term));
		return mb_substr($term, 0, self::MAX_TERM_LENGTH);
	}
}
