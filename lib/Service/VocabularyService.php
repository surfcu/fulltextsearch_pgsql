<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Tools\Fold;
use OCP\IDBConnection;

/**
 * Typo correction from a vocabulary of every word in the index.
 *
 * For each search word it finds alternatives that are ORed into the query, so the original
 * word still matches and nothing that matched before is lost:
 *  - accent variants: "calisma" also searches "çalışma", "sozles" also "sözleş…", and
 *    the other way round;
 *  - spelling corrections, only for words that appear nowhere in the index: "rpaor" also
 *    searches "rapor". Trigram similarity shortlists candidates; an edit distance that counts
 *    swapped letters as one edit decides ("recieve" -> "receive").
 *
 * The vocabulary is instance-wide, while candidate visibility is probed against the viewer's
 * filtered documents before dedup, distance selection, or slicing. Words are added as documents
 * are indexed and never removed individually; rebuild() drops words that no longer occur.
 */
class VocabularyService {

	private const MIN_LENGTH = 3;
	private const MAX_LENGTH = 40;
	/** Spelling correction needs a little more context than accent folding. */
	private const MIN_TYPO_LENGTH = 4;
	private const MAX_VARIANTS = 3;
	private const MAX_CORRECTIONS = 2;
	private const ACCENT_LIMIT = 50;
	/** Loose on purpose: transpositions score low. The edit distance is the real filter. */
	public const TRIGRAM_THRESHOLD = 0.2;
	private const SHORTLIST = 50;

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
	) {
	}

	/**
	 * Add the words of one indexed document. One INSERT … SELECT computed in PostgreSQL;
	 * words are inserted in sorted order so concurrent indexers cannot deadlock.
	 */
	public function addDocument(string $providerId, string $documentId, bool $withContent = true, bool $withParts = true): void {
		$this->db->executeStatement(
			$this->insertSql('d.provider_id = ? AND d.document_id = ?', $withContent, $withParts),
			[$providerId, $documentId]
		);
	}

	/**
	 * Recreate the vocabulary from the whole index, in batches of documents.
	 *
	 * @param callable(int $done, int $total): void|null $progress
	 * @return int number of distinct words
	 */
	public function rebuild(?callable $progress = null, int $batchSize = 500): int {
		$t = $this->schemaService->getTableName();
		$w = $this->schemaService->getWordsTableName();

		$result = $this->db->executeQuery("SELECT count(*), coalesce(max(id), 0) FROM $t");
		[$total, $maxId] = array_map('intval', $result->fetch(\PDO::FETCH_NUM) ?: [0, 0]);
		$result->closeCursor();

		$this->db->executeStatement("TRUNCATE $w");
		$done = 0;
		for ($from = 0; $from < $maxId; $from += $batchSize) {
			$range = [(string)$from, (string)($from + $batchSize)];
			$this->db->executeStatement($this->insertSql('d.id > ? AND d.id <= ?'), $range);
			$done = min($total, $done + $this->countBetween($from, $from + $batchSize));
			if ($progress !== null) {
				$progress($done, $total);
			}
		}

		return $this->count();
	}

	public function count(): int {
		$result = $this->db->executeQuery('SELECT count(*) FROM ' . $this->schemaService->getWordsTableName());
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @param list<string> $words lowercase search words (already Turkish-normalised)
	 * @param bool $spelling also correct misspellings (needs pg_trgm); accents work without it
	 * @param callable(list<string>, bool=): list<string> $visible returns the subset visible to the viewer (prefix=false for exact surface candidates, prefix=true for typed words)
	 * @param (callable(list<string>): list<string>)|null $stopwords returns stopwords to exclude from spelling correction
	 * @return array<string, list<string>> word => alternative words to OR into the query
	 */
	public function alternatives(array $words, bool $spelling, callable $visible, ?callable $stopwords = null): array {
		/** @var list<string> $validWords */
		$validWords = [];
		foreach (array_unique($words) as $word) {
			$length = mb_strlen($word);
			if ($length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH && preg_match('/^\p{L}+$/u', $word) === 1) {
				$validWords[] = $word;
			}
		}
		if ($validWords === []) {
			return [];
		}

		$w = $this->schemaService->getWordsTableName();
		$branches = [];
		$params = [];
		foreach ($validWords as $idx => $word) {
			$folded = Fold::fold($word);
			$prefix = mb_strlen($word) >= self::MIN_TYPO_LENGTH;
			$branches[] = "(SELECT $idx AS idx, word FROM $w WHERE "
				. ($prefix ? 'folded LIKE ?' : 'folded = ?')
				. ' AND word NOT LIKE ? ORDER BY length(word), word LIMIT ' . self::ACCENT_LIMIT . ')';
			$params[] = $prefix ? $this->likePrefix($folded) : $folded;
			$params[] = $this->likePrefix($word);

			if ($spelling && $prefix) {
				// Skip probing ftspg_oc_index when no vocabulary word starts with this prefix.
				$branches[] = "(SELECT $idx AS idx, ''::text AS word FROM $w WHERE word LIKE ? LIMIT 1)";
				$params[] = $this->likePrefix($word);
			}
		}

		$result = $this->db->executeQuery(implode(' UNION ALL ', $branches), $params);
		$phase1Rows = $result->fetchAll(\PDO::FETCH_NUM);
		$result->closeCursor();

		/** @var array<int, list<string>> $accentCandidates */
		$accentCandidates = [];
		/** @var array<int, bool> $hasVocabPrefix */
		$hasVocabPrefix = [];
		foreach ($phase1Rows as $row) {
			$idx = (int)$row[0];
			$candidate = (string)$row[1];
			if ($candidate === '') {
				$hasVocabPrefix[$idx] = true;
			} else {
				$accentCandidates[$idx][] = $candidate;
			}
		}

		/** @var list<string> $accentProbe */
		$accentProbe = [];
		/** @var list<string> $typedProbe */
		$typedProbe = [];
		foreach ($validWords as $idx => $word) {
			if (isset($accentCandidates[$idx])) {
				usort(
					$accentCandidates[$idx],
					static fn (string $a, string $b): int => (mb_strlen($a) <=> mb_strlen($b)) ?: strcmp($a, $b)
				);
				array_push($accentProbe, ...$accentCandidates[$idx]);
			}
			if ($spelling && mb_strlen($word) >= self::MIN_TYPO_LENGTH && ($hasVocabPrefix[$idx] ?? false)) {
				$typedProbe[] = $word;
			}
		}

		/** @var array<string, true> $accentVisibleMap */
		$accentVisibleMap = [];
		if ($accentProbe !== []) {
			foreach ($visible(array_values(array_unique($accentProbe)), false) as $v) {
				$accentVisibleMap[$v] = true;
			}
		}

		/** @var array<string, true> $typedVisibleMap */
		$typedVisibleMap = [];
		if ($typedProbe !== []) {
			foreach ($visible(array_values(array_unique($typedProbe)), true) as $v) {
				$typedVisibleMap[$v] = true;
			}
		}

		$alternatives = [];
		/** @var list<string> $needSpelling */
		$needSpelling = [];
		foreach ($validWords as $idx => $word) {
			$variants = [];
			foreach ($accentCandidates[$idx] ?? [] as $candidate) {
				if (!isset($accentVisibleMap[$candidate])) {
					continue;
				}
				// Drop variants already covered by a shorter visible one ("çalış" covers "çalışma").
				foreach ($variants as $kept) {
					if (str_starts_with($candidate, $kept)) {
						continue 2;
					}
				}
				$variants[] = $candidate;
				if (count($variants) >= self::MAX_VARIANTS) {
					break;
				}
			}

			if ($variants !== []) {
				$alternatives[$word] = $variants;
			} elseif ($spelling && mb_strlen($word) >= self::MIN_TYPO_LENGTH && !isset($typedVisibleMap[$word])) {
				$needSpelling[] = $word;
			}
		}

		if ($needSpelling !== [] && $stopwords !== null) {
			$stopMap = array_flip($stopwords($needSpelling));
			$needSpelling = array_values(array_filter(
				$needSpelling,
				static fn (string $word): bool => !isset($stopMap[$word])
			));
		}

		if ($needSpelling === []) {
			return $alternatives;
		}

		$spellingBranches = [];
		$spellingParams = [];
		$foldedInputs = [];
		$maxDistances = [];
		foreach ($needSpelling as $idx => $word) {
			$folded = Fold::fold($word);
			$length = mb_strlen($folded);
			$maxDistance = $length >= 7 ? 2 : 1;
			$foldedInputs[$idx] = $folded;
			$maxDistances[$idx] = $maxDistance;
			$spellingBranches[] = "(SELECT $idx AS idx, word, folded, similarity(folded, ?) AS score
				FROM $w
				WHERE folded % ? AND length(folded) BETWEEN ? AND ?
				ORDER BY score DESC, word
				LIMIT " . self::SHORTLIST . ')';
			array_push(
				$spellingParams,
				$folded,
				$folded,
				(string)($length - $maxDistance),
				(string)($length + $maxDistance)
			);
		}

		$result = $this->db->executeQuery(implode(' UNION ALL ', $spellingBranches), $spellingParams);
		$phase2Rows = $result->fetchAll(\PDO::FETCH_NUM);
		$result->closeCursor();

		/** @var array<int, list<array{0: int, 1: float, 2: string}>> $scoredByIdx */
		$scoredByIdx = [];
		/** @var list<string> $probePhase2 */
		$probePhase2 = [];
		foreach ($phase2Rows as $row) {
			$idx = (int)$row[0];
			$candidate = (string)$row[1];
			$distance = self::editDistance($foldedInputs[$idx], (string)$row[2]);
			if ($distance <= $maxDistances[$idx]) {
				$scoredByIdx[$idx][] = [$distance, -(float)$row[3], $candidate];
				$probePhase2[] = $candidate;
			}
		}

		if ($probePhase2 === []) {
			return $alternatives;
		}

		/** @var array<string, true> $visibleMap2 */
		$visibleMap2 = [];
		foreach ($visible(array_values(array_unique($probePhase2)), false) as $v) {
			$visibleMap2[$v] = true;
		}

		foreach ($needSpelling as $idx => $word) {
			$visibleScored = array_values(array_filter(
				$scoredByIdx[$idx] ?? [],
				static fn (array $c): bool => isset($visibleMap2[$c[2]])
			));
			if ($visibleScored === []) {
				continue;
			}
			usort(
				$visibleScored,
				static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: strcmp($a[2], $b[2])
			);
			$best = $visibleScored[0][0];
			$closest = array_filter($visibleScored, static fn (array $c): bool => $c[0] === $best);
			$alternatives[$word] = array_slice(array_column($closest, 2), 0, self::MAX_CORRECTIONS);
		}

		return $alternatives;
	}

	/**
	 * Optimal string alignment distance: insertions, deletions, substitutions and swaps of
	 * adjacent letters each count as one edit. Multibyte-safe.
	 */
	public static function editDistance(string $a, string $b): int {
		$s = mb_str_split($a);
		$t = mb_str_split($b);
		$n = count($s);
		$m = count($t);
		$d = [];
		for ($i = 0; $i <= $n; $i++) {
			$d[$i] = [$i];
		}
		for ($j = 0; $j <= $m; $j++) {
			$d[0][$j] = $j;
		}
		for ($i = 1; $i <= $n; $i++) {
			for ($j = 1; $j <= $m; $j++) {
				$cost = $s[$i - 1] === $t[$j - 1] ? 0 : 1;
				$d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
				if ($i > 1 && $j > 1 && $s[$i - 1] === $t[$j - 2] && $s[$i - 2] === $t[$j - 1]) {
					$d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
				}
			}
		}
		return $d[$n][$m];
	}

	private function insertSql(string $where, bool $withContent = true, bool $withParts = true): string {
		$t = $this->schemaService->getTableName();
		$w = $this->schemaService->getWordsTableName();
		$s = $this->schemaService;
		// The 'simple' configuration lowercases without stemming or stopwords: surface words.
		$fields = [
			$s->normalized('d.title'),
			$s->splitWords($s->normalized('d.title')),
			$s->normalized('d.tags_text'),
		];
		if ($withParts) {
			$fields[] = $s->normalized('d.parts_text');
		}
		if ($withContent) {
			$fields[] = $s->normalized('d.content');
		}
		$text = implode(" || ' ' || ", $fields);
		$min = self::MIN_LENGTH;
		$max = self::MAX_LENGTH;
		return <<<SQL
			INSERT INTO $w (word)
			SELECT DISTINCT w.word
			FROM $t d, unnest(tsvector_to_array(to_tsvector('simple', $text))) AS w(word)
			WHERE $where AND w.word ~ '^[[:alpha:]]{{$min},{$max}}$'
			ORDER BY w.word
			ON CONFLICT (word) DO NOTHING
			SQL;
	}

	private function countBetween(int $from, int $to): int {
		$result = $this->db->executeQuery(
			'SELECT count(*) FROM ' . $this->schemaService->getTableName() . ' WHERE id > ? AND id <= ?',
			[(string)$from, (string)$to]
		);
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function likePrefix(string $value): string {
		return addcslashes($value, '\\%_') . '%';
	}
}
