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
	 * Recreate the vocabulary from the whole index in keyset batches into a shadow table,
	 * then swap it into place atomically under an advisory lock so corrections keep working.
	 *
	 * @param callable(int $done, int $total): void|null $progress
	 * @return int number of distinct words
	 */
	public function rebuild(?callable $progress = null, int $batchSize = 500): int {
		$batchSize = max(1, $batchSize);
		$t = $this->schemaService->getQualifiedTableName();
		$w = $this->schemaService->getWordsTableName();
		$qw = $this->schemaService->getQualifiedWordsTableName();
		$qs = $this->schemaService->getQualifiedSchemaName();
		$shadow = $w . '_rebuild';
		$qShadow = $qs . '.' . $this->schemaService->quoteIdentifier($shadow);
		$qWordsIdent = $this->schemaService->quoteIdentifier($w);

		$ownTransaction = !$this->db->inTransaction();
		$savepoint = 'ftspg_vocab_rebuild';
		if ($ownTransaction) {
			$this->db->beginTransaction();
		} else {
			$this->db->executeStatement("SAVEPOINT $savepoint");
		}

		try {
			$this->db->executeQuery(
				'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
				['fulltextsearch_pgsql', $w]
			)->closeCursor();

			$totalRes = $this->db->executeQuery("SELECT count(*) FROM $t");
			$total = (int)$totalRes->fetchOne();
			$totalRes->closeCursor();

			$this->db->executeStatement("DROP TABLE IF EXISTS $qShadow");
			$this->db->executeStatement(sprintf(<<<'SQL'
				CREATE TABLE %1$s (
					word   text COLLATE "C" PRIMARY KEY,
					folded text COLLATE "C" GENERATED ALWAYS AS (translate(word, '%2$s', '%3$s')) STORED
				)
				SQL, $qShadow, Fold::FROM, Fold::TO));

			$done = 0;
			$lastId = 0;
			while (true) {
				$batchRes = $this->db->executeQuery(
					"SELECT count(*), coalesce(max(b.id), 0) FROM (SELECT d.id FROM $t d WHERE d.id > ? ORDER BY d.id LIMIT $batchSize) b",
					[(string)$lastId]
				);
				[$batchCount, $nextId] = array_map('intval', $batchRes->fetch(\PDO::FETCH_NUM) ?: [0, 0]);
				$batchRes->closeCursor();
				if ($batchCount === 0) {
					break;
				}

				$this->db->executeStatement($this->insertSql('d.id > ? AND d.id <= ?', true, true, $qShadow), [(string)$lastId, (string)$nextId]);
				$lastId = $nextId;
				$done = min($total, $done + $batchCount);
				if ($progress !== null) {
					$progress($done, $total);
				}
			}

			$qShadowPkey = $qs . '.' . $this->schemaService->quoteIdentifier("{$shadow}_pkey");
			$qShadowFoldedIdx = $this->schemaService->quoteIdentifier("{$shadow}_folded_idx");
			$qWordsPkey = $this->schemaService->quoteIdentifier("{$w}_pkey");
			$qWordsFoldedIdx = $this->schemaService->quoteIdentifier("{$w}_folded_idx");

			$this->db->executeStatement("CREATE INDEX $qShadowFoldedIdx ON $qShadow (folded)");
			$ts = $this->schemaService->getTrigramSchema();
			if ($ts !== null) {
				$qShadowTrgmIdx = $this->schemaService->quoteIdentifier("{$shadow}_folded_trgm_idx");
				$this->db->executeStatement("CREATE INDEX $qShadowTrgmIdx ON $qShadow USING GIN (folded {$ts}.gin_trgm_ops)");
			}
			$this->db->executeStatement("ANALYZE $qShadow");

			$this->db->executeStatement("DROP TABLE IF EXISTS $qw");
			$this->db->executeStatement("ALTER TABLE $qShadow RENAME TO $qWordsIdent");
			$this->db->executeStatement("ALTER INDEX $qShadowPkey RENAME TO $qWordsPkey");
			$this->db->executeStatement("ALTER INDEX {$qs}.{$qShadowFoldedIdx} RENAME TO $qWordsFoldedIdx");
			if ($ts !== null) {
				$qShadowTrgmIdx = $this->schemaService->quoteIdentifier("{$shadow}_folded_trgm_idx");
				$qWordsTrgmIdx = $this->schemaService->quoteIdentifier("{$w}_folded_trgm_idx");
				$this->db->executeStatement("ALTER INDEX {$qs}.{$qShadowTrgmIdx} RENAME TO $qWordsTrgmIdx");
			}

			$wordCount = $this->count();
			if ($ownTransaction) {
				$this->db->commit();
			} else {
				$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
			}
			return $wordCount;
		} catch (\Throwable $e) {
			if ($ownTransaction) {
				if ($this->db->inTransaction()) {
					try {
						$this->db->rollBack();
					} catch (\Throwable) {
					}
				}
			} elseif ($this->db->inTransaction()) {
				try {
					$this->db->executeStatement("ROLLBACK TO SAVEPOINT $savepoint");
					$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
				} catch (\Throwable) {
				}
			}
			throw $e;
		}
	}

	public function count(): int {
		$result = $this->db->executeQuery('SELECT count(*) FROM ' . $this->schemaService->getQualifiedWordsTableName());
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
		$validWords = self::filterValidWords($words);
		if ($validWords === []) {
			return [];
		}

		$canSpell = $spelling && $this->schemaService->hasTrigram();
		$w = $this->schemaService->getQualifiedWordsTableName();
		$ts = $canSpell ? $this->schemaService->getTrigramSchema() : null;
		$simFn = $ts !== null ? "{$ts}.similarity" : 'similarity';
		$simOp = $ts !== null ? "OPERATOR({$ts}.%)" : '%';

		$branches = [];
		$params = [];
		$foldedInputs = [];
		$maxDistances = [];
		foreach ($validWords as $idx => $word) {
			$folded = Fold::fold($word);
			$prefix = mb_strlen($word) >= self::MIN_TYPO_LENGTH;
			$branches[] = "(SELECT $idx AS idx, 0 AS kind, word, ''::text AS folded, 0.0::float8 AS score FROM $w WHERE "
				. ($prefix ? 'folded LIKE ?' : 'folded = ?')
				. ' AND word NOT LIKE ? ORDER BY length(word), word LIMIT ' . self::ACCENT_LIMIT . ')';
			$params[] = $prefix ? $this->likePrefix($folded) : $folded;
			$params[] = $this->likePrefix($word);

			if ($canSpell && $prefix) {
				$branches[] = "(SELECT $idx AS idx, 1 AS kind, ''::text AS word, ''::text AS folded, 0.0::float8 AS score FROM $w WHERE word LIKE ? LIMIT 1)";
				$params[] = $this->likePrefix($word);

				$length = mb_strlen($folded);
				$maxDistance = $length >= 7 ? 2 : 1;
				$foldedInputs[$idx] = $folded;
				$maxDistances[$idx] = $maxDistance;
				$branches[] = "(SELECT $idx AS idx, 2 AS kind, word, folded, {$simFn}(folded, ?) AS score
					FROM $w
					WHERE folded {$simOp} ? AND length(folded) BETWEEN ? AND ?
					ORDER BY score DESC, word
					LIMIT " . self::SHORTLIST . ')';
				$params = [
					...$params,
					$folded,
					$folded,
					(string)($length - $maxDistance),
					(string)($length + $maxDistance),
				];
			}
		}

		$result = $this->db->executeQuery(implode(' UNION ALL ', $branches), $params);
		$rows = $result->fetchAll(\PDO::FETCH_NUM);
		$result->closeCursor();

		/** @var array<int, list<string>> $accentCandidates */
		$accentCandidates = [];
		/** @var array<int, bool> $hasVocabPrefix */
		$hasVocabPrefix = [];
		/** @var array<int, list<array{0: int, 1: float, 2: string}>> $scoredByIdx */
		$scoredByIdx = [];
		foreach ($rows as $row) {
			$idx = (int)$row[0];
			$kind = (int)$row[1];
			if ($kind === 0) {
				$accentCandidates[$idx][] = (string)$row[2];
			} elseif ($kind === 1) {
				$hasVocabPrefix[$idx] = true;
			} elseif (isset($foldedInputs[$idx], $maxDistances[$idx])) {
				$candidate = (string)$row[2];
				$distance = self::editDistance($foldedInputs[$idx], (string)$row[3]);
				if ($distance <= $maxDistances[$idx]) {
					$scoredByIdx[$idx][] = [$distance, -(float)$row[4], $candidate];
				}
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
			if ($canSpell && mb_strlen($word) >= self::MIN_TYPO_LENGTH && ($hasVocabPrefix[$idx] ?? false)) {
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

		$alternatives = self::selectAccentVariants($validWords, $accentCandidates, $accentVisibleMap);

		/** @var array<int, string> $needSpelling */
		$needSpelling = [];
		foreach ($validWords as $idx => $word) {
			if (!isset($alternatives[$word]) && $canSpell && mb_strlen($word) >= self::MIN_TYPO_LENGTH && !isset($typedVisibleMap[$word])) {
				$needSpelling[$idx] = $word;
			}
		}

		if ($needSpelling !== [] && $stopwords !== null) {
			$stopMap = array_flip($stopwords(array_values($needSpelling)));
			foreach ($needSpelling as $idx => $word) {
				if (isset($stopMap[$word])) {
					unset($needSpelling[$idx]);
				}
			}
		}

		if ($needSpelling === []) {
			return $alternatives;
		}

		/** @var list<string> $probePhase2 */
		$probePhase2 = [];
		foreach ($needSpelling as $idx => $_) {
			foreach ($scoredByIdx[$idx] ?? [] as $scored) {
				$probePhase2[] = $scored[2];
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
			$chosen = self::pickClosestCorrections($scoredByIdx[$idx] ?? [], $visibleMap2);
			if ($chosen !== []) {
				$alternatives[$word] = $chosen;
			}
		}

		return $alternatives;
	}

	/**
	 * Pass-1 lookup for SearchService: fetches accent variants and per-word vocabulary prefix
	 * presence in one statement so Pass 2 never re-queries Phase 1.
	 *
	 * @param list<string> $words
	 * @param callable(list<string>, bool=): list<string> $visible
	 * @return array{0: array<string, list<string>>, 1: array<string, bool>}
	 */
	public function accentAndPrefixLookup(array $words, callable $visible): array {
		$validWords = self::filterValidWords($words);
		if ($validWords === []) {
			return [[], []];
		}

		$w = $this->schemaService->getQualifiedWordsTableName();
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

			if ($prefix) {
				$branches[] = "(SELECT $idx AS idx, ''::text AS word FROM $w WHERE word LIKE ? LIMIT 1)";
				$params[] = $this->likePrefix($word);
			}
		}

		$result = $this->db->executeQuery(implode(' UNION ALL ', $branches), $params);
		$rows = $result->fetchAll(\PDO::FETCH_NUM);
		$result->closeCursor();

		/** @var array<int, list<string>> $accentCandidates */
		$accentCandidates = [];
		/** @var array<string, bool> $hasVocabPrefix */
		$hasVocabPrefix = [];
		foreach ($rows as $row) {
			$idx = (int)$row[0];
			$candidate = (string)$row[1];
			if ($candidate === '') {
				$hasVocabPrefix[$validWords[$idx]] = true;
			} else {
				$accentCandidates[$idx][] = $candidate;
			}
		}

		/** @var list<string> $accentProbe */
		$accentProbe = [];
		foreach ($validWords as $idx => $_) {
			if (isset($accentCandidates[$idx])) {
				usort(
					$accentCandidates[$idx],
					static fn (string $a, string $b): int => (mb_strlen($a) <=> mb_strlen($b)) ?: strcmp($a, $b)
				);
				array_push($accentProbe, ...$accentCandidates[$idx]);
			}
		}

		/** @var array<string, true> $accentVisibleMap */
		$accentVisibleMap = [];
		if ($accentProbe !== []) {
			foreach ($visible(array_values(array_unique($accentProbe)), false) as $v) {
				$accentVisibleMap[$v] = true;
			}
		}

		return [self::selectAccentVariants($validWords, $accentCandidates, $accentVisibleMap), $hasVocabPrefix];
	}

	/**
	 * Pass-2 spelling lookup reusing Pass-1 accent variants and vocabulary prefix flags.
	 *
	 * @param list<string> $words
	 * @param array<string, list<string>> $accentAlternatives
	 * @param array<string, bool> $hasVocabPrefix
	 * @param callable(list<string>, list<string>, list<string>): array{0: list<string>, 1: list<string>, 2: list<string>} $batchProbe
	 * @param array<string, bool> $knownInvisible
	 * @return array<string, list<string>>
	 */
	public function spellingFromLookup(
		array $words,
		array $accentAlternatives,
		array $hasVocabPrefix,
		callable $batchProbe,
		array $knownInvisible = [],
	): array {
		if (!$this->schemaService->hasTrigram()) {
			return $accentAlternatives;
		}
		$validWords = self::filterValidWords($words);
		$candidates = [];
		$typedProbe = [];
		foreach ($validWords as $word) {
			if (isset($accentAlternatives[$word]) || mb_strlen($word) < self::MIN_TYPO_LENGTH) {
				continue;
			}
			$candidates[] = $word;
			if (($hasVocabPrefix[$word] ?? false) && !isset($knownInvisible[$word])) {
				$typedProbe[] = $word;
			}
		}
		if ($candidates === []) {
			return $accentAlternatives;
		}

		$deferredStopCheck = ($typedProbe === []);
		if (!$deferredStopCheck) {
			[, $typedVisible, $stops] = $batchProbe([], $typedProbe, $candidates);
			$typedVisibleMap = array_fill_keys($typedVisible, true);
			$stopMap = array_fill_keys($stops, true);
			$candidates = array_values(array_filter(
				$candidates,
				static fn (string $word): bool => !isset($typedVisibleMap[$word]) && !isset($stopMap[$word])
			));
			if ($candidates === []) {
				return $accentAlternatives;
			}
		}

		$w = $this->schemaService->getQualifiedWordsTableName();
		$ts = $this->schemaService->getTrigramSchema();
		$simFn = $ts !== null ? "{$ts}.similarity" : 'similarity';
		$simOp = $ts !== null ? "OPERATOR({$ts}.%)" : '%';
		$spellingBranches = [];
		$spellingParams = [];
		$foldedInputs = [];
		$maxDistances = [];
		foreach ($candidates as $idx => $word) {
			$folded = Fold::fold($word);
			$length = mb_strlen($folded);
			$maxDistance = $length >= 7 ? 2 : 1;
			$foldedInputs[$idx] = $folded;
			$maxDistances[$idx] = $maxDistance;
			$spellingBranches[] = "(SELECT $idx AS idx, word, folded, {$simFn}(folded, ?) AS score
				FROM $w
				WHERE folded {$simOp} ? AND length(folded) BETWEEN ? AND ?
				ORDER BY score DESC, word
				LIMIT " . self::SHORTLIST . ')';
			$spellingParams = [
				...$spellingParams,
				$folded,
				$folded,
				(string)($length - $maxDistance),
				(string)($length + $maxDistance),
			];
		}

		$result = $this->db->executeQuery(implode(' UNION ALL ', $spellingBranches), $spellingParams);
		$rows = $result->fetchAll(\PDO::FETCH_NUM);
		$result->closeCursor();

		/** @var array<int, list<array{0: int, 1: float, 2: string}>> $scoredByIdx */
		$scoredByIdx = [];
		/** @var list<string> $probePhase2 */
		$probePhase2 = [];
		foreach ($rows as $row) {
			$idx = (int)$row[0];
			$candidate = (string)$row[1];
			$distance = self::editDistance($foldedInputs[$idx], (string)$row[2]);
			if ($distance <= $maxDistances[$idx]) {
				$scoredByIdx[$idx][] = [$distance, -(float)$row[3], $candidate];
				$probePhase2[] = $candidate;
			}
		}
		if ($probePhase2 === []) {
			return $accentAlternatives;
		}

		[$exactVisible, , $deferredStops] = $batchProbe(
			array_values(array_unique($probePhase2)),
			[],
			$deferredStopCheck ? $candidates : []
		);
		$visibleMap2 = array_fill_keys($exactVisible, true);
		$stopMap2 = array_fill_keys($deferredStops, true);

		$alternatives = $accentAlternatives;
		foreach ($candidates as $idx => $word) {
			if (isset($stopMap2[$word])) {
				continue;
			}
			$chosen = self::pickClosestCorrections($scoredByIdx[$idx] ?? [], $visibleMap2);
			if ($chosen !== []) {
				$alternatives[$word] = $chosen;
			}
		}
		return $alternatives;
	}

	/**
	 * @param list<string> $words
	 * @return list<string>
	 */
	private static function filterValidWords(array $words): array {
		$valid = [];
		foreach ($words as $rawWord) {
			$word = Fold::lowercase($rawWord);
			if (in_array($word, $valid, true)) {
				continue;
			}
			$length = mb_strlen($word);
			if ($length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH && preg_match('/^\p{L}+$/u', $word) === 1) {
				$valid[] = $word;
			}
		}
		return $valid;
	}

	/**
	 * @param list<string> $validWords
	 * @param array<int, list<string>> $accentCandidates
	 * @param array<string, true> $accentVisibleMap
	 * @return array<string, list<string>>
	 */
	private static function selectAccentVariants(array $validWords, array $accentCandidates, array $accentVisibleMap): array {
		$alternatives = [];
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
			}
		}
		return $alternatives;
	}

	/**
	 * @param list<array{0: int, 1: float, 2: string}> $scored
	 * @param array<string, true> $visibleMap
	 * @return list<string>
	 */
	private static function pickClosestCorrections(array $scored, array $visibleMap): array {
		$visibleScored = array_values(array_filter(
			$scored,
			static fn (array $c): bool => isset($visibleMap[$c[2]])
		));
		if ($visibleScored === []) {
			return [];
		}
		usort(
			$visibleScored,
			static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: strcmp($a[2], $b[2])
		);
		$best = $visibleScored[0][0];
		$closest = array_filter($visibleScored, static fn (array $c): bool => $c[0] === $best);
		return array_slice(array_column($closest, 2), 0, self::MAX_CORRECTIONS);
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

	private function insertSql(string $where, bool $withContent = true, bool $withParts = true, ?string $targetWordsTable = null): string {
		$t = $this->schemaService->getQualifiedTableName();
		$w = $targetWordsTable ?? $this->schemaService->getQualifiedWordsTableName();
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
			SELECT DISTINCT w.word COLLATE "C" AS word
			FROM $t d, unnest(tsvector_to_array(to_tsvector('pg_catalog.simple', $text))) AS w(word)
			WHERE $where AND w.word ~ '^[[:alpha:]]{{$min},{$max}}$'
			ORDER BY word
			ON CONFLICT (word) DO NOTHING
			SQL;
	}

	private function likePrefix(string $value): string {
		return addcslashes($value, '\\%_') . '%';
	}
}
