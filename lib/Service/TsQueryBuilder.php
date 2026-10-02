<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Model\ParsedQuery;

/**
 * Turns a user's search string into parameterised SQL.
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
 * With substring matching on (needs pg_trgm), a word of 3+ characters also matches when it
 * appears anywhere inside a title: "butce" finds "YillikButceRaporu.xlsx".
 *
 * User text never reaches SQL or the tsquery syntax unescaped: each term is a bound
 * parameter, quoted as a tsquery literal or escaped as a LIKE pattern.
 */
class TsQueryBuilder {

	private const MAX_TERMS = 32;
	private const MAX_TERM_LENGTH = 100;
	private const MIN_SUBSTRING_LENGTH = 3;

	/** Same split as SchemaService::splitWords(), applied to search words. */
	private const SPLIT_PATTERNS = [
		'/(\p{L})(\p{N})/u' => '$1 $2',
		'/(\p{N})(\p{L})/u' => '$1 $2',
		'/[_.\-\/\\\\+~,;:()\[\]{}]+/u' => ' ',
	];

	/**
	 * The plain positive words of a search (not phrases, not exclusions), lowercased the way
	 * build() matches alternatives to them. These are the words typo correction looks at.
	 *
	 * @return list<string>
	 */
	public function searchWords(string $search, string $language): array {
		$words = [];
		foreach ($this->tokenize($search, $language) as $m) {
			$isPhrase = !isset($m[4]) || $m[4] === '';
			if ($isPhrase || $m[3] === '-') {
				continue;
			}
			$word = $this->trimTerm($m[4]);
			if ($word !== '' && !in_array($word, ['OR', 'AND'], true)) {
				$words[] = mb_strtolower($word, 'UTF-8');
			}
		}
		return array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);
	}

	/**
	 * @param bool $substring also match words inside titles (title_search LIKE '%word%')
	 * @param array<string, list<string>> $alternatives lowercase word => words ORed in for it
	 * @return ParsedQuery|null null when there is nothing positive to look for
	 */
	public function build(string $search, string $language, bool $substring = false, array $alternatives = []): ?ParsedQuery {
		$matches = $this->tokenize($search, $language);

		/** @var array<string, list<array{ts: array{0: string, 1: list<string>}, pred: array{0: string, 1: list<string>}}>> $terms */
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
				$ts = ['phraseto_tsquery(CAST(? AS regconfig), ?)', [$language, $text]];
			} else {
				$alts = $kind === 'excluded' ? [] : ($alternatives[mb_strtolower($text, 'UTF-8')] ?? []);
				$ts = ['to_tsquery(CAST(? AS regconfig), ?)', [$language, $this->wordQuery($text, $kind !== 'excluded', $alts)]];
			}

			$pred = ['d.tsv @@ ' . $ts[0], $ts[1]];
			if ($substring && !$isPhrase && $kind !== 'excluded' && mb_strlen($text) >= self::MIN_SUBSTRING_LENGTH) {
				$pattern = '%' . addcslashes(mb_strtolower($text, 'UTF-8'), '\\%_') . '%';
				// numnode() = 0 for stopwords ("the"), which must not substring-match "Other".
				// Its arguments are constants, so PostgreSQL evaluates it once while planning.
				$pred = [
					'(' . $pred[0] . ' OR (numnode(' . $ts[0] . ') > 0 AND d.title_search LIKE ?))',
					[...$ts[1], ...$ts[1], $pattern],
				];
			}

			$terms[$kind][] = ['ts' => $ts, 'pred' => $pred];
			if ($kind !== 'excluded') {
				$words[] = $text;
			}
			$count++;
		}

		$required = array_column($terms['required'], 'pred');
		$optional = array_column($terms['optional'], 'pred');
		$excluded = array_column($terms['excluded'], 'ts');

		// A query made only of exclusions would scan the whole table and match nearly everything.
		$base = $required !== [] ? $this->join($required, 'AND') : ($optional !== [] ? $this->join($optional, 'OR') : null);
		if ($base === null) {
			return null;
		}

		$match = $base;
		if ($excluded !== []) {
			$none = $this->join($excluded, '||');
			$match = ['(' . $base[0] . ' AND NOT (d.tsv @@ ' . $none[0] . '))', [...$base[1], ...$none[1]]];
		}

		/** @var list<array{0: string, 1: list<string>}> $positiveTs */
		$positiveTs = array_merge(array_column($terms['required'], 'ts'), array_column($terms['optional'], 'ts'));
		/** @var list<array{0: string, 1: list<string>}> $positivePreds */
		$positivePreds = array_merge($required, $optional);
		if ($positiveTs === [] || $positivePreds === []) {
			return null; // unreachable: $base above guarantees a positive term
		}
		$rank = $this->join($positiveTs, '||');
		$all = $this->join($positivePreds, 'AND');

		return new ParsedQuery($match[0], $match[1], $rank[0], $rank[1], $all[0], $all[1], implode(' ', $words));
	}

	/**
	 * tsquery text for one word: the word itself, or, when it contains punctuation or
	 * letter/digit boundaries ("final.pdf", "rapor_2025"), either the whole word or its parts
	 * in sequence. The whole form still matches host names and e-mail addresses in content;
	 * the parts match the split copy of titles.
	 */
	/** @param list<string> $alternatives typo corrections and accent variants, prefix-matched */
	private function wordQuery(string $word, bool $prefix, array $alternatives = []): string {
		$suffix = $prefix ? ':*' : '';
		$forms = [$this->literal($word) . $suffix];

		$split = trim((string)preg_replace(array_keys(self::SPLIT_PATTERNS), array_values(self::SPLIT_PATTERNS), $word));
		$parts = preg_split('/\s+/u', $split, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (count($parts) >= 2) {
			$forms[] = implode(' <-> ', array_map(fn (string $p): string => $this->literal($p) . $suffix, $parts));
		}
		foreach ($alternatives as $alternative) {
			$forms[] = $this->literal($alternative) . ':*';
		}

		return count($forms) === 1 ? $forms[0] : '(' . implode(') | (', $forms) . ')';
	}

	/** @return list<array<int, string>> regex matches: [1] phrase operator, [2] phrase, [3] word operator, [4] word */
	private function tokenize(string $search, string $language): array {
		$search = mb_scrub($search, 'UTF-8');
		if ($language === 'turkish') {
			// Same dotted/dotless I mapping as the indexed text (see SchemaService::normalized()).
			$search = strtr($search, ['I' => 'ı', 'İ' => 'i']);
		}
		preg_match_all('/([+-]?)"([^"]*)"?|([+-]?)(\S+)/u', $search, $matches, PREG_SET_ORDER);
		return $matches;
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
