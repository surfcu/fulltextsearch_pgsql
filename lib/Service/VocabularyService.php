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
 * The vocabulary is instance-wide, but alternatives only widen a search that is still
 * filtered by access control, so results never include documents the user cannot see.
 * Words are added as documents are indexed and never removed individually; rebuild()
 * drops words that no longer occur.
 */
class VocabularyService {

	private const MIN_LENGTH = 3;
	private const MAX_LENGTH = 40;
	/** Spelling correction needs a little more context than accent folding. */
	private const MIN_TYPO_LENGTH = 4;
	private const MAX_VARIANTS = 3;
	private const MAX_CORRECTIONS = 2;
	/** Loose on purpose: transpositions score low. The edit distance is the real filter. */
	private const TRIGRAM_THRESHOLD = 0.2;
	private const SHORTLIST = 20;

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
	) {
	}

	/**
	 * Add the words of one indexed document. One INSERT … SELECT computed in PostgreSQL;
	 * words are inserted in sorted order so concurrent indexers cannot deadlock.
	 */
	public function addDocument(string $providerId, string $documentId): void {
		$this->db->executeStatement(
			$this->insertSql('d.provider_id = ? AND d.document_id = ?'),
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
	 * @return array<string, list<string>> word => alternative words to OR into the query
	 */
	public function alternatives(array $words, bool $spelling): array {
		$alternatives = [];
		foreach (array_unique($words) as $word) {
			$length = mb_strlen($word);
			if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || !preg_match('/^\p{L}+$/u', $word)) {
				continue;
			}

			$found = $this->accentVariants($word);
			if ($spelling && $length >= self::MIN_TYPO_LENGTH && $found === [] && !$this->isKnownPrefix($word)) {
				$found = $this->spellingCorrections($word);
			}
			if ($found !== []) {
				$alternatives[$word] = $found;
			}
		}
		return $alternatives;
	}

	/**
	 * Words that fold to the same letters, or (for 4+ letters) start with them, in either
	 * direction: "calis" -> "çalış", and "çalışma" -> "calisma" for text typed without
	 * diacritics. Shortest first, since alternatives are prefix-matched and the shortest
	 * covers the longer forms. Words starting with what was typed already match and are skipped.
	 *
	 * @return list<string>
	 */
	private function accentVariants(string $word): array {
		$folded = Fold::fold($word);
		$w = $this->schemaService->getWordsTableName();
		$prefix = mb_strlen($word) >= self::MIN_TYPO_LENGTH;
		$result = $this->db->executeQuery(
			"SELECT word FROM $w WHERE " . ($prefix ? 'folded LIKE ?' : 'folded = ?')
			. ' AND word NOT LIKE ? ORDER BY length(word), word LIMIT ' . (self::MAX_VARIANTS * 4),
			[$prefix ? $this->likePrefix($folded) : $folded, $this->likePrefix($word)]
		);
		$candidates = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		// Drop variants already covered by a shorter one ("çalış" covers "çalışma").
		$variants = [];
		foreach ($candidates as $candidate) {
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
		return $variants;
	}

	/** Some indexed word starts with this one, so it is a valid (possibly partial) word. */
	private function isKnownPrefix(string $word): bool {
		$result = $this->db->executeQuery(
			'SELECT 1 FROM ' . $this->schemaService->getWordsTableName() . ' WHERE word LIKE ? LIMIT 1',
			[$this->likePrefix($word)]
		);
		$known = $result->fetchOne() !== false;
		$result->closeCursor();
		return $known;
	}

	/** @return list<string> */
	private function spellingCorrections(string $word): array {
		$folded = Fold::fold($word);
		$w = $this->schemaService->getWordsTableName();
		$length = mb_strlen($folded);
		$maxDistance = $length >= 7 ? 2 : 1;

		$this->db->executeStatement('SET pg_trgm.similarity_threshold = ' . self::TRIGRAM_THRESHOLD);
		try {
			$result = $this->db->executeQuery(
				"SELECT word, folded, similarity(folded, ?) AS score FROM $w
				 WHERE folded % ? AND length(folded) BETWEEN ? AND ?
				 ORDER BY score DESC, word LIMIT " . self::SHORTLIST,
				[$folded, $folded, (string)($length - $maxDistance), (string)($length + $maxDistance)]
			);
			$rows = $result->fetchAll();
			$result->closeCursor();
		} finally {
			$this->db->executeStatement('RESET pg_trgm.similarity_threshold');
		}

		$scored = [];
		foreach ($rows as $row) {
			$distance = self::editDistance($folded, (string)$row['folded']);
			if ($distance <= $maxDistance) {
				$scored[] = [$distance, -(float)$row['score'], (string)$row['word']];
			}
		}
		sort($scored);
		// Keep only the closest candidates: "invoice" (1 edit) but not also "jniocie" (2).
		$best = $scored[0][0] ?? null;
		$closest = array_filter($scored, fn (array $c): bool => $c[0] === $best);
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

	private function insertSql(string $where): string {
		$t = $this->schemaService->getTableName();
		$w = $this->schemaService->getWordsTableName();
		$s = $this->schemaService;
		// The 'simple' configuration lowercases without stemming or stopwords: surface words.
		$text = "{$s->normalized('d.title')} || ' ' || {$s->splitWords($s->normalized('d.title'))} || ' ' || "
			. "{$s->normalized('d.tags_text')} || ' ' || {$s->normalized('d.parts_text')} || ' ' || {$s->normalized('d.content')}";
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
