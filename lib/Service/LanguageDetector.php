<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Picks, among the configured languages, the one a document is written in.
 *
 * PostgreSQL already knows each language's stop words ("ve", "için", "the", "which",
 * "und"...): they are the commonest words of a language and the ones its dictionary
 * drops. Counting them in a sample of the text, per configured language, tells the
 * languages apart reliably once a text has a sentence or two. No word list to maintain,
 * and it covers every language the server ships.
 *
 * Titles, file names and very short texts have no stop words: they get the first
 * (fallback) language, as does a text where no language clearly wins.
 */
class LanguageDetector {

	/** Characters of text looked at; enough for a clear count, cheap to analyse. */
	private const SAMPLE_CHARS = 2000;

	/** A language needs at least this many stop words to be chosen over the fallback. */
	private const MIN_STOPWORDS = 2;

	/** ...and this many times more than the runner-up. */
	private const MARGIN = 1.5;

	/** Token types that are words (ts_debug aliases), as opposed to numbers, URLs, e-mails. */
	private const WORD_TOKENS = ['asciiword', 'word', 'numword', 'asciihword', 'hword', 'numhword', 'hword_asciipart', 'hword_part', 'hword_numpart'];

	public function __construct(
		private IDBConnection $db,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string ...$texts candidates in order of preference (content, then comments,
	 *                         then title): the first non-empty one is sampled
	 */
	public function detect(string ...$texts): string {
		$languages = $this->configService->getLanguages();
		if (count($languages) === 1) {
			return $languages[0];
		}

		$sample = '';
		foreach ($texts as $text) {
			if (trim($text) !== '') {
				$sample = mb_substr($text, 0, self::SAMPLE_CHARS, 'UTF-8');
				break;
			}
		}
		if ($sample === '') {
			return $languages[0];
		}

		$scores = $this->scores($sample, $languages);
		arsort($scores);
		$ranked = array_keys($scores);
		$best = $ranked[0] ?? $languages[0];
		$bestScore = $scores[$best] ?? 0;
		$second = isset($ranked[1]) ? $scores[$ranked[1]] : 0;

		if ($bestScore >= self::MIN_STOPWORDS && $bestScore >= $second * self::MARGIN) {
			return (string)$best;
		}
		return $languages[0];
	}

	/**
	 * Stop words found in $text for each language.
	 *
	 * @param list<string> $languages
	 * @return array<string, int>
	 */
	public function scores(string $text, array $languages): array {
		$scores = array_fill_keys($languages, 0);
		try {
			// Turkish is lowercased the Turkish way (I -> ı, İ -> i), as indexed text is.
			$result = $this->db->executeQuery(
				"SELECT cfg::text AS language,
						count(*) FILTER (WHERE t.lexemes = '{}' AND t.dictionary IS NOT NULL) AS stopwords
				 FROM unnest(CAST(? AS regconfig[])) AS cfg,
					  LATERAL ts_debug(cfg, CASE WHEN cfg = 'turkish'::regconfig THEN translate(?, 'Iİ', 'ıi') ELSE ? END) AS t
				 WHERE t.alias = ANY (CAST(? AS text[]))
				 GROUP BY cfg",
				['{' . implode(',', $languages) . '}', $text, $text, '{' . implode(',', self::WORD_TOKENS) . '}']
			);
			foreach ($result->fetchAll() as $row) {
				$scores[(string)$row['language']] = (int)$row['stopwords'];
			}
			$result->closeCursor();
		} catch (Throwable $e) {
			// A language removed from the server since it was configured, for instance.
			$this->logger->warning('Language detection failed, using the fallback language', ['exception' => $e]);
		}
		return $scores;
	}
}
