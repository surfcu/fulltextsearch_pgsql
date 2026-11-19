<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch_PgSql\Exceptions\DocumentNotFoundException;
use OCA\FullTextSearch_PgSql\Model\ParsedQuery;
use OCA\FullTextSearch_PgSql\Tools\PgArray;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IDBConnection;

class SearchService {

	private const FRAGMENT_DELIMITER = '|||';
	/** Searches with more positive terms than this are ranked as one query, without IDF weights. */
	private const MAX_IDF_TERMS = 8;
	/** random_page_cost for search statements, unless the server's is lower (see withSearchPlanning()). */
	private const SEARCH_RANDOM_PAGE_COST = 1.1;
	/** A search starts from the filters when they select this many times fewer rows than its words (see startFromFilters()). */
	private const FILTERS_FIRST_RATIO = 10;
	/** Below this share of the index, matched by the terms together, the matching scan is fenced (see idfRanking()). */
	private const FENCE_FRACTION = 0.25;
	/** Terms matching more documents than this get the planner's estimate instead of an exact count. */
	private const EXACT_FREQUENCY_LIMIT = 10000;
	/**
	 * Excerpts of longer texts come from this many characters around the first match: ts_headline
	 * tokenizes all the text it is given, 70–90 ms for a 500 KB document.
	 */
	private const EXCERPT_WINDOW = 10000;
	/** Characters kept before the first match, so a fragment can start a little earlier. */
	private const EXCERPT_LEAD = 500;
	/** Longest regex or wildcard filter pattern accepted from a search request. */
	private const MAX_FILTER_PATTERN = 256;
	/** Search request fields and the tsvector weight they are indexed with (see SchemaService). */
	private const FIELD_WEIGHTS = ['title' => 'A', 'tags' => 'B', 'parts' => 'C', 'content' => 'D'];
	/** Columns regex and wildcard filters can test. */
	private const FILTER_COLUMNS = ['title' => 'd.title', 'content' => 'd.content'];
	/** Scalar string columns addressable through ISearchRequestSimpleQuery. */
	private const SIMPLE_STRING_COLUMNS = [
		'title' => 'd.title',
		'content' => 'd.content',
		'source' => 'd.source',
		'hash' => 'd.hash',
		'owner' => 'd.owner_id',
		'owner_id' => 'd.owner_id',
		'provider' => 'd.provider_id',
		'provider_id' => 'd.provider_id',
		'document_id' => 'd.document_id',
	];
	/** String-array columns addressable through ISearchRequestSimpleQuery. */
	private const SIMPLE_ARRAY_COLUMNS = [
		'tags' => 'd.tags',
		'metatags' => 'd.metatags',
		'subtags' => 'd.subtags',
		'links' => 'd.links',
	];
	/** Integer columns addressable through ISearchRequestSimpleQuery. */
	private const SIMPLE_INT_COLUMNS = [
		'modified_time' => 'd.modified_time',
		'lastModified' => 'd.modified_time',
	];
	/** Internal or access-list fields that must never fall through to d.info. */
	private const SIMPLE_RESERVED_FIELDS = [
		'id' => true,
		'indexed_at' => true,
		'config' => true,
		'access' => true,
		'users' => true,
		'groups' => true,
		'circles' => true,
		'tsv' => true,
		'title_search' => true,
		'tags_text' => true,
		'parts_text' => true,
		'parts' => true,
		'info' => true,
		'subtags_map' => true,
		'combined' => true,
		'more' => true,
	];
	private const DEFAULT_TRIGRAM_THRESHOLD = 0.3;
	private const WORD_SIMILARITY_THRESHOLD = 0.6;
	private const HEADLINE_OPTIONS = 'StartSel="",StopSel="",MaxFragments=3,MaxWords=30,MinWords=12,FragmentDelimiter="' . self::FRAGMENT_DELIMITER . '"';

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
		private ConfigService $configService,
		private TsQueryBuilder $queryBuilder,
		private VocabularyService $vocabulary,
	) {
	}

	public function searchRequest(ISearchResult $result, IDocumentAccess $access): void {
		try {
			$this->withSearchPlanning(fn () => $this->search($result, $access));
		} catch (\Throwable $e) {
			if (self::isTimeoutException($e)) {
				$result->setTimedOut(true);
				$result->setTotal(0);
				return;
			}
			if (self::isInvalidRegexException($e)) {
				$result->setTotal(0);
				return;
			}
			throw $e;
		}
	}

	/**
	 * Detect whether an exception represents PostgreSQL SQLSTATE 2201B
	 * (ERRCODE_INVALID_REGULAR_EXPRESSION).
	 */
	private static function isInvalidRegexException(\Throwable $e): bool {
		for ($curr = $e; $curr !== null; $curr = $curr->getPrevious()) {
			if (method_exists($curr, 'getSQLState') && $curr->getSQLState() === '2201B') {
				return true;
			}
			if ((string)$curr->getCode() === '2201B') {
				return true;
			}
			if ($curr instanceof \PDOException && isset($curr->errorInfo[0]) && (string)$curr->errorInfo[0] === '2201B') {
				return true;
			}
			$msg = $curr->getMessage();
			if (stripos($msg, 'invalid regular expression') !== false) {
				return true;
			}
			if (preg_match('/(?:SQLSTATE\[2201B\]|\bSQLSTATE\s+2201B\b|invalid_regular_expression)/i', $msg) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect whether an exception represents PostgreSQL SQLSTATE 57014
	 * (ERRCODE_QUERY_CANCELED, statement_timeout).
	 */
	public static function isTimeoutException(\Throwable $e): bool {
		for ($curr = $e; $curr !== null; $curr = $curr->getPrevious()) {
			if (method_exists($curr, 'getSQLState') && $curr->getSQLState() === '57014') {
				return true;
			}
			if ((string)$curr->getCode() === '57014') {
				return true;
			}
			if ($curr instanceof \PDOException && isset($curr->errorInfo[0]) && (string)$curr->errorInfo[0] === '57014') {
				return true;
			}
			$msg = $curr->getMessage();
			if (stripos($msg, 'canceling statement due to statement timeout') !== false) {
				return true;
			}
			if (preg_match('/(?:SQLSTATE\[57014\]|\bSQLSTATE\s+57014\b|query_canceled)/i', $msg) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs a search's statements in a transaction of their own, with planner settings that
	 * keep PostgreSQL on the full-text GIN index (SET LOCAL, so nothing else on the connection
	 * is affected).
	 *
	 * The index table's heap is small: titles, arrays and pointers, with every body and search
	 * vector stored out of line (TOAST). PostgreSQL's cost model sees a cheap heap and charges
	 * almost nothing for evaluating tsv @@ query on a row, though for a TOASTed vector that means
	 * fetching and decompressing it, and it overestimates GIN scans. After ANALYZE it therefore
	 * preferred reading every row the user can see, sequentially or through the access index,
	 * and testing each vector: 1.3 s instead of 0.2–0.3 s for a search matching 12,000 of 100,000
	 * documents, 61 ms instead of 8 ms on 4,000. startFromFilters() picks the index the search
	 * starts from and hides the other; these settings keep the planner from avoiding it:
	 *  - enable_seqscan off: a sequential scan stays possible, but only as a last resort;
	 *  - random_page_cost at most SEARCH_RANDOM_PAGE_COST (1.1, the usual value for SSDs, rather
	 *    than 4 for spinning disks), so index scans are priced closer to what they cost; a server
	 *    configured lower keeps its value;
	 *  - jit off: before PostgreSQL 18, a disabled plan type adds a huge constant to plan costs,
	 *    enough to trigger JIT compilation, which costs more than any search.
	 *
	 * @param callable(): void $search
	 */
	private function withSearchPlanning(callable $search): void {
		$ownTransaction = !$this->db->inTransaction();
		$savepoint = 'ftspg_search_plan';
		if ($ownTransaction) {
			$this->db->beginTransaction();
		}
		$savepointCreated = false;
		$failed = true;
		$previous = false;
		$hasTrigram = false;
		try {
			if (!$ownTransaction) {
				$this->db->executeStatement("SAVEPOINT $savepoint");
				$savepointCreated = true;
			}
			$timeout = $ownTransaction ? $this->configService->getSearchTimeout() : null;
			$hasTrigram = $this->schemaService->hasTrigram();

			// Snapshot previous settings before changing them, inside the try so failures roll back.
			$prevResult = $this->db->executeQuery(
				"SELECT current_setting('enable_seqscan'), current_setting('random_page_cost'), current_setting('jit'), current_setting('pg_trgm.similarity_threshold', true), current_setting('pg_trgm.word_similarity_threshold', true)"
			);
			$previous = $prevResult->fetch(\PDO::FETCH_NUM);
			$prevResult->closeCursor();

			$setCols = [
				"set_config('enable_seqscan', 'off', true)",
				"set_config('random_page_cost', least(current_setting('random_page_cost')::float8, CAST(? AS float8))::text, true)",
				"set_config('jit', 'off', true)",
			];
			$params = [
				(string)self::SEARCH_RANDOM_PAGE_COST,
			];
			if ($hasTrigram) {
				$setCols[] = "set_config('pg_trgm.similarity_threshold', CAST(? AS text), true)";
				$params[] = (string)VocabularyService::TRIGRAM_THRESHOLD;
				$setCols[] = "set_config('pg_trgm.word_similarity_threshold', CAST(? AS text), true)";
				$params[] = (string)self::WORD_SIMILARITY_THRESHOLD;
			}
			if ($timeout !== null) {
				$setCols[] = "set_config('statement_timeout', CAST(? AS text), true)";
				$params[] = $timeout > 0 ? ($timeout * 1000) . 'ms' : '0';
			}
			$this->db->executeQuery('SELECT ' . implode(', ', $setCols), $params)->closeCursor();

			$search();
			$failed = false;
		} finally {
			if (!$ownTransaction) {
				// Inside a caller's transaction: roll back the savepoint on error so neither aborted
				// state nor modified GUCs leak; on success restore GUCs and release the savepoint.
				if ($failed) {
					if ($savepointCreated && $this->db->inTransaction()) {
						try {
							$this->db->executeStatement("ROLLBACK TO SAVEPOINT $savepoint");
							$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
						} catch (\Throwable) {
						}
					}
				} else {
					try {
						if (is_array($previous)) {
							$restoreCols = [
								"set_config('enable_seqscan', ?, true)",
								"set_config('random_page_cost', ?, true)",
								"set_config('jit', ?, true)",
							];
							$restoreParams = [(string)$previous[0], (string)$previous[1], (string)$previous[2]];
							if ($hasTrigram) {
								$restoreCols[] = "set_config('pg_trgm.similarity_threshold', ?, true)";
								$restoreParams[] = ($previous[3] ?? null) !== null ? (string)$previous[3] : (string)self::DEFAULT_TRIGRAM_THRESHOLD;
								$restoreCols[] = "set_config('pg_trgm.word_similarity_threshold', ?, true)";
								$restoreParams[] = ($previous[4] ?? null) !== null ? (string)$previous[4] : (string)self::WORD_SIMILARITY_THRESHOLD;
							}
							$this->db->executeQuery('SELECT ' . implode(', ', $restoreCols), $restoreParams)->closeCursor();
						}
						if ($savepointCreated && $this->db->inTransaction()) {
							$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
						}
					} catch (\Throwable $restoreEx) {
						if ($savepointCreated && $this->db->inTransaction()) {
							try {
								$this->db->executeStatement("ROLLBACK TO SAVEPOINT $savepoint");
								$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
							} catch (\Throwable) {
							}
						}
						throw $restoreEx;
					}
				}
			} elseif ($failed) {
				$this->db->rollBack();
			} else {
				$this->db->commit();
			}
		}
	}

	private function search(ISearchResult $result, IDocumentAccess $access): void {
		$start = microtime(true);
		$request = $result->getRequest();
		$providerId = $result->getProvider()->getId();

		$viewerTokens = AccessTokens::forViewer($access);
		$weights = $this->limitWeights($request->getLimitFields());
		if ($weights === '' || $viewerTokens === []) {
			// No access tokens, or limited to fields this platform does not index.
			$result->setTotal(0);
			return;
		}

		$languages = $this->configService->getLanguages();
		$search = $request->getSearch();
		// Words as each language tokenizes them (Turkish lowercases I to ı), reused for alternatives and excerpts.
		$words = [];
		foreach ($languages as $language) {
			array_push($words, ...$this->queryBuilder->searchWords($search, $language));
		}
		$words = array_values(array_unique($words));

		[$filterConditions, $filterParams] = $this->filters($request, $providerId, $viewerTokens, $languages);

		$alternatives = [];
		$typoOn = $this->configService->useTypoCorrection() && $words !== [];
		$visible = null;
		$stopwords = null;
		if ($typoOn) {
			[$visible, $stopwords] = $this->vocabularyProbes($languages, $weights, $filterConditions, $filterParams);
			$alternatives = $this->vocabulary->alternatives($words, false, $visible, $stopwords);
		}

		$queries = $this->buildQueries($search, $languages, $alternatives, $weights);
		if ($queries === null) {
			$result->setTotal(0);
			return;
		}
		$query = $queries[$languages[0]];

		$size = max(1, min($request->getSize(), $this->configService->getMaxResults()));
		$page = max(1, $request->getPage());
		$maxOffset = PHP_INT_MAX - $size;
		$offset = ($page - 1) > intdiv($maxOffset, $size) ? $maxOffset : ($page - 1) * $size;
		$filtersFirst = $this->startFromFilters($queries, $filterConditions, $filterParams);
		$filterSql = self::renderFilters($filterConditions, !$filtersFirst);

		$rows = $this->fullTextSearch($queries, $filterSql, $filterParams, $filtersFirst, $size, $offset, $words);

		// Gate spelling correction to first-page searches with fewer than $size uncorrected hits.
		if ($typoOn && $this->fuzzyAvailable() && $visible !== null && $stopwords !== null && $page === 1 && count($rows) < $size) {
			$pass2Visible = $visible;
			if ($rows === [] && $query->plainWordsOnly && $alternatives === []) {
				// Pass 1 already proved none of the words tested in $queries match under the active filters.
				$tested = [];
				foreach ($queries as $q) {
					if ($q->plainText !== '') {
						foreach (explode(' ', mb_strtolower($q->plainText, 'UTF-8')) as $tw) {
							$tested[$tw] = true;
						}
					}
				}
				$knownInvisible = array_intersect_key($tested, array_flip($words));
				if ($knownInvisible !== []) {
					$pass2Visible = static function (array $probeWords, bool $prefix = false) use ($visible, $knownInvisible): array {
						$toProbe = array_values(array_filter(
							$probeWords,
							static fn (string $w): bool => !isset($knownInvisible[$w])
						));
						return $toProbe === [] ? [] : $visible($toProbe, $prefix);
					};
				}
			}
			$withSpelling = $this->vocabulary->alternatives($words, true, $pass2Visible, $stopwords);
			if ($withSpelling !== $alternatives) {
				$queries = $this->buildQueries($search, $languages, $withSpelling, $weights);
				if ($queries === null) {
					$result->setTotal(0);
					return;
				}
				$query = $queries[$languages[0]];
				$filtersFirst = $this->startFromFilters($queries, $filterConditions, $filterParams);
				$filterSql = self::renderFilters($filterConditions, !$filtersFirst);
				$rows = $this->fullTextSearch($queries, $filterSql, $filterParams, $filtersFirst, $size, 0, $words);
			}
		}

		if ($rows !== []) {
			$total = (int)$rows[0]['total'];
		} elseif ($page > 1) {
			$total = $this->fullTextCount($queries, $filterSql, $filterParams, $filtersFirst);
		} else {
			$total = 0;
		}

		$titlesSearched = $weights === null || str_contains($weights, 'A');
		if ($total === 0 && $titlesSearched && $this->fuzzyAvailable() && $query->plainText !== '' && $query->plainWordsOnly) {
			[$nonStopSql, $nonStopParams] = $this->nonStopwordCondition($queries);
			// Nothing matched exactly: fall back to typo-tolerant matching on titles ("rapr" -> "rapor").
			$rows = $this->titleSimilaritySearch($query->plainText, $nonStopSql, $nonStopParams, $filterSql, $filterParams, $size, $offset);
			if ($rows !== []) {
				$total = (int)$rows[0]['total'];
			} elseif ($page > 1) {
				$total = $this->titleSimilarityCount($query->plainText, $nonStopSql, $nonStopParams, $filterSql, $filterParams);
			}
		}

		$maxScore = 0;
		foreach ($rows as $row) {
			$score = round((float)$row['rank'] * 100.0, 2);
			$maxScore = max($maxScore, (int)ceil($score));
			$result->addDocument($this->toResultDocument($providerId, $row, (string)$score, $access->getViewerId()));
		}

		$result->setTotal($total);
		$result->setMaxScore($maxScore);
		$result->setTime((int)round((microtime(true) - $start) * 1000.0));
		$result->setTimedOut(false);
	}

	/**
	 * @param non-empty-list<string> $languages
	 * @param array<string, list<string>> $alternatives
	 * @return non-empty-array<string, ParsedQuery>|null
	 */
	private function buildQueries(string $search, array $languages, array $alternatives, ?string $weights): ?array {
		/** @var array<string, ParsedQuery> $queries */
		$queries = [];
		$substring = $this->fuzzyAvailable();
		foreach ($languages as $language) {
			$query = $this->queryBuilder->build($search, $language, $substring, $alternatives, $weights);
			if ($query === null) {
				return null;
			}
			$queries[$language] = $query;
		}
		return $queries === [] ? null : $queries;
	}

	/**
	 * Batched EXISTS visibility and stopword probes under the search's active filters.
	 *
	 * @param non-empty-list<string> $languages
	 * @param list<string> $filterConditions
	 * @param list<string> $filterParams
	 * @return array{0: callable(list<string>, bool=): list<string>, 1: callable(list<string>): list<string>}
	 */
	private function vocabularyProbes(array $languages, ?string $weights, array $filterConditions, array $filterParams): array {
		$t = $this->schemaService->getQualifiedTableName();
		$langConditions = self::languageConditions($languages);
		// Hide provider_id from the planner so EXISTS uses GIN (tsv/access) instead of scanning every row via doc_uniq.
		$probeConditions = $filterConditions;
		if ($probeConditions !== []) {
			$probeConditions[0] = "({$probeConditions[0]}) IS TRUE";
		}
		$probeFilterSql = self::renderFilters($probeConditions, false);
		$hasA = $weights === null || str_contains($weights, 'A');
		$hasB = $weights === null || str_contains($weights, 'B');
		$hasC = $weights === null || str_contains($weights, 'C');
		$hasD = $weights === null || str_contains($weights, 'D');
		$titleNorm = $hasA ? $this->schemaService->normalized('d.title') : '';
		$titleExpr = $hasA ? $titleNorm . " || ' ' || " . $this->schemaService->splitWords($titleNorm) : '';
		$tagsNorm = $hasB ? $this->schemaService->normalized('d.tags_text') : '';
		$partsNorm = $hasC ? $this->schemaService->normalized('d.parts_text') : '';
		$contentNorm = $hasD ? $this->schemaService->normalized('d.content') : '';
		/** @var array<string, bool> $stopwordCache */
		$stopwordCache = [];

		$visible = function (array $probeWords, bool $prefix = false) use (
			$t,
			$languages,
			$langConditions,
			$weights,
			$probeFilterSql,
			$filterParams,
			$hasA,
			$hasB,
			$hasC,
			$hasD,
			$titleExpr,
			$tagsNorm,
			$partsNorm,
			$contentNorm,
			&$stopwordCache
		): array {
			$unique = array_values(array_unique($probeWords));
			if ($unique === []) {
				return [];
			}
			$visibleWords = [];
			foreach (array_chunk($unique, 200) as $chunk) {
				$selects = [];
				$params = [];
				foreach ($chunk as $word) {
					$branches = [];
					$branchParams = [];
					$stopChecks = [];
					$stopParams = [];
					foreach ($languages as $langIdx => $language) {
						[$langCond, $langCondParams] = $langConditions[$langIdx];
						[$tsSql, $tsParams] = $this->queryBuilder->wordTsQuery($word, $language, $weights);
						$branches[] = "(numnode($tsSql) > 0 AND $langCond AND d.tsv @@ $tsSql)";
						array_push($branchParams, ...$tsParams, ...$langCondParams, ...$tsParams);
						$stopChecks[] = "numnode($tsSql) = 0";
						array_push($stopParams, ...$tsParams);
					}
					$matchSql = count($branches) === 1 ? $branches[0] : '(' . implode(' OR ', $branches) . ')';

					// Verify unstemmed surface occurrence ('simple') across active weighted fields so stemmed/prefix neighbors cannot leak.
					// When $prefix is true, match only ^[[:alpha:]]{3,40}$ lexemes like VocabularyService::insertSql().
					$needle = mb_strtolower($word, 'UTF-8');
					$simpleTsSql = '';
					$simpleTsParams = [];
					if (!$prefix) {
						[$simpleTsSql, $simpleTsParams] = $this->queryBuilder->wordTsQuery($word, 'pg_catalog.simple', null, false);
					}
					$surfaceMatch = static function (string $expr) use ($prefix, $needle, $simpleTsSql, $simpleTsParams): array {
						if ($prefix) {
							return [
								"EXISTS (SELECT 1 FROM unnest(to_tsvector('pg_catalog.simple', $expr)) AS w WHERE w.lexeme ~ '^[[:alpha:]]{3,40}$' AND starts_with(w.lexeme, ?))",
								[$needle],
							];
						}
						return ["to_tsvector('pg_catalog.simple', $expr) @@ $simpleTsSql", $simpleTsParams];
					};
					$surfaceBranches = [];
					$surfaceParams = [];
					if ($hasA) {
						[$exprSql, $exprParams] = $surfaceMatch($titleExpr);
						$surfaceBranches[] = "((d.title <> '') IS TRUE AND $exprSql)";
						array_push($surfaceParams, ...$exprParams);
					}
					if ($hasB) {
						[$exprSql, $exprParams] = $surfaceMatch($tagsNorm);
						$surfaceBranches[] = "((d.tags_text <> '') IS TRUE AND $exprSql)";
						array_push($surfaceParams, ...$exprParams);
					}
					if ($hasC) {
						[$exprSql, $exprParams] = $surfaceMatch($partsNorm);
						$surfaceBranches[] = "((d.parts_text <> '') IS TRUE AND strpos(lower($partsNorm), ?) > 0 AND $exprSql)";
						$surfaceParams[] = $needle;
						array_push($surfaceParams, ...$exprParams);
					}
					if ($hasD) {
						[$exprSql, $exprParams] = $surfaceMatch($contentNorm);
						$surfaceBranches[] = "((d.content <> '') IS TRUE AND strpos(lower($contentNorm), ?) > 0 AND $exprSql)";
						$surfaceParams[] = $needle;
						array_push($surfaceParams, ...$exprParams);
					}
					$surfaceSql = $surfaceBranches === [] ? 'FALSE' : (count($surfaceBranches) === 1 ? $surfaceBranches[0] : '(' . implode(' OR ', $surfaceBranches) . ')');

					$selects[] = "EXISTS (SELECT 1 FROM $t d WHERE $matchSql AND $surfaceSql AND $probeFilterSql)";
					array_push($params, ...$branchParams, ...$surfaceParams, ...$filterParams);
					$selects[] = '(' . implode(' OR ', $stopChecks) . ')';
					array_push($params, ...$stopParams);
				}
				$result = $this->db->executeQuery('SELECT ' . implode(', ', $selects), $params);
				$row = $result->fetch(\PDO::FETCH_NUM);
				$result->closeCursor();
				if (!is_array($row)) {
					continue;
				}
				foreach ($chunk as $i => $word) {
					$stopwordCache[$word] = in_array($row[$i * 2 + 1] ?? false, [true, 1, '1', 't'], true);
					if (in_array($row[$i * 2] ?? false, [true, 1, '1', 't'], true)) {
						$visibleWords[] = $word;
					}
				}
			}
			return $visibleWords;
		};

		$stopwords = function (array $checkWords) use ($languages, $weights, &$stopwordCache): array {
			$unique = array_values(array_unique($checkWords));
			$missing = array_values(array_filter($unique, static fn (string $w): bool => !array_key_exists($w, $stopwordCache)));
			foreach (array_chunk($missing, 200) as $chunk) {
				$selects = [];
				$params = [];
				foreach ($chunk as $word) {
					$stopChecks = [];
					foreach ($languages as $language) {
						[$tsSql, $tsParams] = $this->queryBuilder->wordTsQuery($word, $language, $weights);
						$stopChecks[] = "numnode($tsSql) = 0";
						array_push($params, ...$tsParams);
					}
					$selects[] = '(' . implode(' OR ', $stopChecks) . ')';
				}
				$result = $this->db->executeQuery('SELECT ' . implode(', ', $selects), $params);
				$row = $result->fetch(\PDO::FETCH_NUM);
				$result->closeCursor();
				if (is_array($row)) {
					foreach ($chunk as $i => $word) {
						$stopwordCache[$word] = in_array($row[$i] ?? false, [true, 1, '1', 't'], true);
					}
				}
			}
			return array_values(array_filter($unique, static fn (string $w): bool => $stopwordCache[$w] ?? false));
		};

		return [$visible, $stopwords];
	}

	/**
	 * @throws DocumentNotFoundException
	 */
	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		$result = $this->db->executeQuery(
			'SELECT owner_id, array_to_json(access) AS access, array_to_json(links) AS links,
					array_to_json(tags) AS tags, array_to_json(metatags) AS metatags, array_to_json(subtags) AS subtags,
					source, hash, modified_time, title, parts, content, info
			 FROM ' . $this->schemaService->getQualifiedTableName() . ' WHERE provider_id = ? AND document_id = ?',
			[$providerId, $documentId]
		);
		$row = $result->fetch();
		$result->closeCursor();
		if (!is_array($row)) {
			throw new DocumentNotFoundException("Document $providerId:$documentId is not indexed");
		}

		$ownerId = (string)$row['owner_id'];
		$shares = AccessTokens::split($this->decode($row['access']), $ownerId);
		$access = new DocumentAccess($ownerId);
		$access->setUsers($shares['users']);
		$access->setGroups($shares['groups']);
		$access->setCircles($shares['circles']);
		$access->setLinks($this->decode($row['links']));

		$document = new IndexDocument($providerId, $documentId);
		$document->setAccess($access);
		$document->setTags($this->decode($row['tags']));
		$document->setMetaTags($this->decode($row['metatags']));
		$document->setSubTags($this->decode($row['subtags']));
		$document->setSource((string)$row['source']);
		$document->setHash((string)$row['hash']);
		$document->setModifiedTime((int)$row['modified_time']);
		$document->setTitle((string)$row['title']);
		$document->setParts($this->decode($row['parts']));
		$document->setContent((string)$row['content']);
		foreach ($this->decode($row['info']) as $key => $value) {
			match (true) {
				is_array($value) => $document->setInfoArray((string)$key, $value),
				is_bool($value) => $document->setInfoBool((string)$key, $value),
				is_int($value) => $document->setInfoInt((string)$key, $value),
				default => $document->setInfo((string)$key, (string)$value),
			};
		}
		return $document;
	}

	/**
	 * @param list<string> $viewerTokens
	 * @param non-empty-list<string> $languages
	 * @return array{0: list<string>, 1: list<string>} conditions (each usable by an index), their params
	 */
	private function filters(ISearchRequest $request, string $providerId, array $viewerTokens, array $languages): array {
		$conditions = ['d.provider_id = ?', 'd.access && CAST(? AS text[])'];
		$params = [$providerId, PgArray::toLiteral($viewerTokens)];

		$rawMetaTags = $request->getMetaTags();
		if ($rawMetaTags !== []) {
			$metaTags = self::validFilterStrings($rawMetaTags);
			if ($metaTags === []) {
				$conditions[] = 'FALSE';
			} else {
				// any of the requested meta tags
				$conditions[] = 'd.metatags && CAST(? AS text[])';
				$params[] = PgArray::toLiteral($metaTags);
			}
		}

		$rawSubTags = $request->getSubTags(true);
		if ($rawSubTags !== []) {
			$allSubStrings = array_values(array_map('strval', $rawSubTags));
			$subTags = self::validFilterStrings($rawSubTags);
			if (count($subTags) !== count($allSubStrings)) {
				$conditions[] = 'FALSE';
			} elseif ($subTags !== []) {
				// all of the requested sub tags
				$conditions[] = 'd.subtags @> CAST(? AS text[])';
				$params[] = PgArray::toLiteral($subTags);
			}
		}

		// Modification date range from unified search's date filter (Unix timestamps, inclusive).
		$since = (int)$request->getOption('since');
		if ($since > 0) {
			$conditions[] = 'd.modified_time >= ?';
			$params[] = (string)$since;
		}
		$until = (int)$request->getOption('until');
		if ($until > 0) {
			$conditions[] = 'd.modified_time <= ?';
			$params[] = (string)$until;
		}

		// Each filter is a group of entries (field => pattern), any of which may match; every group
		// must match. Same semantics as the Elasticsearch platform. The Files app's "filter by
		// extension" arrives as one regex group with one entry: [['title' => '.*\.pdf']].
		foreach ($request->getWildcardFilters() as $group) {
			[$groupSql, $groupParams] = $this->patternGroup($group, false);
			$conditions[] = $groupSql;
			array_push($params, ...$groupParams);
		}
		foreach ($request->getRegexFilters() as $group) {
			[$groupSql, $groupParams] = $this->patternGroup($group, true);
			$conditions[] = $groupSql;
			array_push($params, ...$groupParams);
		}
		foreach ($request->getSimpleQueries() as $simpleQuery) {
			if (!$simpleQuery instanceof ISearchRequestSimpleQuery) {
				$conditions[] = 'FALSE';
				continue;
			}
			[$sqSql, $sqParams] = $this->simpleQueryCondition($simpleQuery, $languages);
			$conditions[] = $sqSql;
			array_push($params, ...$sqParams);
		}

		return [$conditions, $params];
	}

	/**
	 * Compile one ISearchRequestSimpleQuery into a parameterised SQL predicate.
	 *
	 * @param non-empty-list<string> $languages
	 * @return array{0: string, 1: list<string>}
	 */
	private function simpleQueryCondition(ISearchRequestSimpleQuery $query, array $languages): array {
		$target = self::resolveSimpleField($query->getField());
		$rawValues = $query->getValues();
		if ($target === null || $rawValues === []) {
			return ['FALSE', []];
		}
		[$kind, $ref] = $target;
		$type = $query->getType();

		if ($type === ISearchRequestSimpleQuery::COMPARE_TYPE_KEYWORD || $type === ISearchRequestSimpleQuery::COMPARE_TYPE_ARRAY) {
			$flat = [];
			if ($type === ISearchRequestSimpleQuery::COMPARE_TYPE_ARRAY) {
				foreach ($rawValues as $item) {
					foreach (is_array($item) ? $item : [$item] as $sub) {
						if (is_string($sub) || is_int($sub)) {
							$flat[] = (string)$sub;
						}
					}
				}
			} else {
				foreach ($rawValues as $item) {
					if (is_string($item) || is_int($item)) {
						$flat[] = (string)$item;
					}
				}
			}
			$vals = self::validFilterStrings($flat);
			if ($vals === []) {
				return ['FALSE', []];
			}
			return match ($kind) {
				'string_col' => count($vals) === 1
					? ["$ref = ?", [$vals[0]]]
					: ["$ref = ANY (CAST(? AS text[]))", [PgArray::toLiteral($vals)]],
				'array_col' => count($vals) === 1
					? ["$ref @> CAST(? AS text[])", [PgArray::toLiteral([$vals[0]])]]
					: ["$ref && CAST(? AS text[])", [PgArray::toLiteral($vals)]],
				'int_col' => self::simpleIntIn($ref, $vals),
				'part' => count($vals) === 1
					? ['(pg_catalog.jsonb_exists(d.parts, ?) AND (d.parts ->> ?) = ?)', [$ref, $ref, $vals[0]]]
					: ['(pg_catalog.jsonb_exists(d.parts, ?) AND (d.parts ->> ?) = ANY (CAST(? AS text[])))', [$ref, $ref, PgArray::toLiteral($vals)]],
				'info' => count($vals) === 1
					? [
						"((jsonb_typeof(d.info -> ?) = 'string' AND (d.info ->> ?) = ?) OR (jsonb_typeof(d.info -> ?) = 'array' AND pg_catalog.jsonb_exists_any(d.info -> ?, CAST(? AS text[]))))",
						[$ref, $ref, $vals[0], $ref, $ref, PgArray::toLiteral([$vals[0]])],
					]
					: [
						"((jsonb_typeof(d.info -> ?) = 'string' AND (d.info ->> ?) = ANY (CAST(? AS text[]))) OR (jsonb_typeof(d.info -> ?) = 'array' AND pg_catalog.jsonb_exists_any(d.info -> ?, CAST(? AS text[]))))",
						[$ref, $ref, PgArray::toLiteral($vals), $ref, $ref, PgArray::toLiteral($vals)],
					],
			};
		}

		$intOp = match ($type) {
			ISearchRequestSimpleQuery::COMPARE_TYPE_INT_EQ => '=',
			ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GTE => '>=',
			ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GT => '>',
			ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LTE => '<=',
			ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LT => '<',
			default => null,
		};
		if ($intOp !== null) {
			$first = $rawValues[0] ?? null;
			if (!is_int($first) && !(is_string($first) && preg_match('/^-?\d{1,64}\z/', $first) === 1)) {
				return ['FALSE', []];
			}
			$numStr = (string)$first;
			return match ($kind) {
				'int_col' => filter_var($numStr, FILTER_VALIDATE_INT) !== false
					? ["$ref $intOp ?", [$numStr]]
					: ["($ref::numeric $intOp CAST(? AS numeric))", [$numStr]],
				'info' => [
					"((CASE WHEN jsonb_typeof(d.info -> ?) = 'number' THEN (d.info ->> ?)::numeric END) $intOp CAST(? AS numeric))",
					[$ref, $ref, $numStr],
				],
				default => ['FALSE', []],
			};
		}

		if ($type === ISearchRequestSimpleQuery::COMPARE_TYPE_BOOL) {
			$first = $rawValues[0] ?? null;
			if (is_bool($first)) {
				$boolJson = $first ? 'true' : 'false';
			} elseif ($first === 'true' || $first === 'false') {
				$boolJson = $first;
			} else {
				return ['FALSE', []];
			}
			return $kind === 'info'
				? ['((d.info -> ?) = CAST(? AS jsonb))', [$ref, $boolJson]]
				: ['FALSE', []];
		}

		if ($type === ISearchRequestSimpleQuery::COMPARE_TYPE_WILDCARD || $type === ISearchRequestSimpleQuery::COMPARE_TYPE_REGEX) {
			$compiled = self::compilePattern($rawValues[0] ?? null, $type === ISearchRequestSimpleQuery::COMPARE_TYPE_REGEX);
			if ($compiled === null) {
				return ['FALSE', []];
			}
			[$op, $bound] = $compiled;
			return match ($kind) {
				'string_col' => ["$ref $op ?", [$bound]],
				'array_col' => ["EXISTS (SELECT 1 FROM unnest($ref) AS elem WHERE elem $op ?)", [$bound]],
				'part' => ["(pg_catalog.jsonb_exists(d.parts, ?) AND (d.parts ->> ?) $op ?)", [$ref, $ref, $bound]],
				'info' => ["(jsonb_typeof(d.info -> ?) = 'string' AND (d.info ->> ?) $op ?)", [$ref, $ref, $bound]],
				default => ['FALSE', []],
			};
		}

		if ($type === ISearchRequestSimpleQuery::COMPARE_TYPE_TEXT) {
			$first = $rawValues[0] ?? null;
			if (!is_string($first) || trim($first) === '') {
				return ['FALSE', []];
			}
			$rawField = $query->getField();
			if (isset(self::FIELD_WEIGHTS[$rawField])) {
				$fieldQueries = $this->buildQueries($first, $languages, [], self::FIELD_WEIGHTS[$rawField]);
				return $fieldQueries === null ? ['FALSE', []] : $this->matchCondition($fieldQueries);
			}
			return match ($kind) {
				'string_col' => $this->simpleTextExpr($ref, [], $first, $languages, null),
				'array_col' => $this->simpleTextExpr("array_to_string($ref, ' ')", [], $first, $languages, null),
				'part' => $this->simpleTextExpr('(d.parts ->> ?)', [$ref], $first, $languages, ['pg_catalog.jsonb_exists(d.parts, ?)', [$ref]]),
				'info' => $this->simpleTextExpr('(d.info ->> ?)', [$ref], $first, $languages, ["jsonb_typeof(d.info -> ?) = 'string'", [$ref]]),
				default => ['FALSE', []],
			};
		}

		return ['FALSE', []];
	}

	/**
	 * @return array{0: 'string_col'|'array_col'|'int_col'|'part'|'info', 1: string}|null
	 */
	private static function resolveSimpleField(string $field): ?array {
		if ($field === '' || strlen($field) > self::MAX_FILTER_PATTERN || str_contains($field, "\0") || !mb_check_encoding($field, 'UTF-8')) {
			return null;
		}
		if (isset(self::SIMPLE_STRING_COLUMNS[$field])) {
			return ['string_col', self::SIMPLE_STRING_COLUMNS[$field]];
		}
		if (isset(self::SIMPLE_ARRAY_COLUMNS[$field])) {
			return ['array_col', self::SIMPLE_ARRAY_COLUMNS[$field]];
		}
		if (isset(self::SIMPLE_INT_COLUMNS[$field])) {
			return ['int_col', self::SIMPLE_INT_COLUMNS[$field]];
		}
		if (str_starts_with($field, 'parts.')) {
			$partKey = substr($field, 6);
			return $partKey === '' ? null : ['part', $partKey];
		}
		if (str_starts_with($field, 'info.')) {
			$infoKey = substr($field, 5);
			return ($infoKey === '' || str_starts_with($infoKey, '_')) ? null : ['info', $infoKey];
		}
		if (isset(self::SIMPLE_RESERVED_FIELDS[$field]) || str_starts_with($field, '_')) {
			return null;
		}
		return ['info', $field];
	}

	/**
	 * @param list<string> $vals
	 * @return array{0: string, 1: list<string>}
	 */
	private static function simpleIntIn(string $col, array $vals): array {
		$ints = [];
		foreach ($vals as $val) {
			if (preg_match('/^-?\d{1,64}\z/', $val) === 1 && filter_var($val, FILTER_VALIDATE_INT) !== false) {
				$ints[] = $val;
			}
		}
		if ($ints === []) {
			return ['FALSE', []];
		}
		return count($ints) === 1
			? ["$col = ?", [$ints[0]]]
			: ["$col = ANY (CAST(? AS bigint[]))", ['{' . implode(',', $ints) . '}']];
	}

	/**
	 * @param list<string> $exprParams
	 * @param non-empty-list<string> $languages
	 * @param array{0: string, 1: list<string>}|null $guard
	 * @return array{0: string, 1: list<string>}
	 */
	private function simpleTextExpr(string $expr, array $exprParams, string $text, array $languages, ?array $guard): array {
		$clean = (string)preg_replace('/[^\P{Cc}\t\n\r]+/u', '', mb_scrub($text, 'UTF-8'));
		if (trim($clean) === '') {
			return ['FALSE', []];
		}
		$conditions = self::languageConditions($languages);
		$branches = [];
		$params = [];
		foreach ($languages as $i => $language) {
			[$langCond, $langParams] = $conditions[$i];
			$queryText = $language === 'turkish' ? strtr($clean, ['I' => 'ı', 'İ' => 'i']) : $clean;
			$normExpr = "CASE WHEN d.config = 'turkish' THEN translate($expr, 'Iİ', 'ıi') ELSE $expr END";
			$branches[] = "($langCond AND numnode(plainto_tsquery(CAST(? AS pg_catalog.regconfig), ?)) > 0 AND to_tsvector(CAST(? AS pg_catalog.regconfig), $normExpr) @@ plainto_tsquery(CAST(? AS pg_catalog.regconfig), ?))";
			$params = [...$params, ...$langParams, $language, $queryText, $language, ...$exprParams, ...$exprParams, $language, $queryText];
		}
		$matchSql = count($branches) === 1 ? $branches[0] : '(' . implode(' OR ', $branches) . ')';
		if ($guard !== null) {
			return ["({$guard[0]} AND $matchSql)", [...$guard[1], ...$params]];
		}
		return [$matchSql, $params];
	}

	/**
	 * The filters as one SQL condition. Hidden, each is written as (condition) IS TRUE: the same
	 * rows, but no index can serve it, so the search cannot start from it (see startFromFilters()).
	 *
	 * @param list<string> $conditions
	 */
	private static function renderFilters(array $conditions, bool $hidden): string {
		return implode(' AND ', array_map(static fn (string $c): string => $hidden ? "($c) IS TRUE" : $c, $conditions));
	}

	/**
	 * Whether the search should start from the filters (provider, access, tags) rather than from
	 * the full-text index, decided from the planner's row estimates for each side.
	 *
	 * Either way, every row a plan starts from that is not ruled out by cheap conditions on the
	 * small heap row costs a fetch and decompression of its TOASTed vector, to test tsv @@ query
	 * (always, when title substring matching makes the GIN result inexact). PostgreSQL prices
	 * that test as nearly free and overestimates GIN scans, so left to itself it starts from the
	 * filters even when they cover most of the index: 1.3 s instead of 0.3 s for 12,000 matches
	 * among 90,000 visible documents. And starting from the full-text index when the filters are
	 * narrow (a provider with 40 documents, a user with few files) tests every document containing
	 * a common word: 640 ms instead of 160 ms. So the search starts from the filters only when
	 * they select fewer than a tenth as many rows as the words do, and writes the other side as
	 * IS TRUE (see renderFilters()) so the planner has no other index to start from.
	 *
	 * @param non-empty-array<string, ParsedQuery> $queries
	 * @param list<string> $filterConditions
	 * @param list<string> $filterParams
	 */
	private function startFromFilters(array $queries, array $filterConditions, array $filterParams): bool {
		$t = $this->schemaService->getQualifiedTableName();
		$filtered = $this->estimateRows("SELECT 1 FROM $t d WHERE " . self::renderFilters($filterConditions, false), $filterParams);
		[$matchSql, $matchParams] = $this->matchCondition($queries);
		$matching = $this->estimateRows("SELECT 1 FROM $t d WHERE $matchSql", $matchParams);
		return $filtered * self::FILTERS_FIRST_RATIO < $matching;
	}

	/**
	 * Which rows each language branch applies to, in the order of $languages: documents indexed
	 * in a language no longer configured go with the first.
	 *
	 * @param list<string> $languages
	 * @return list<array{0: string, 1: list<string>}>
	 */
	private static function languageConditions(array $languages): array {
		$others = array_slice($languages, 1);
		$conditions = [];
		foreach ($languages as $i => $language) {
			$conditions[] = $i === 0
				? ($others === [] ? ['TRUE', []] : ['d.config <> ALL (CAST(? AS text[]))', [PgArray::toLiteral($others)]])
				: ['d.config = ?', [$language]];
		}
		return $conditions;
	}

	/**
	 * Which documents match, over every language branch.
	 *
	 * @param non-empty-array<string, ParsedQuery> $queries
	 * @return array{0: string, 1: list<string>}
	 */
	private function matchCondition(array $queries): array {
		$conditions = self::languageConditions(array_keys($queries));
		$match = [];
		$params = [];
		foreach (array_values($queries) as $i => $query) {
			[$condition, $conditionParams] = $conditions[$i];
			$match[] = "($condition AND {$query->sql})";
			array_push($params, ...$conditionParams, ...$query->params);
		}
		return [count($match) === 1 ? $match[0] : '(' . implode(' OR ', $match) . ')', $params];
	}

	/**
	 * One wildcard or regex filter group as SQL: its entries ORed. A group is a list of entries,
	 * each a field => pattern map, as the Elasticsearch platform reads them.
	 *
	 * Like Elasticsearch's wildcard and regexp queries, a pattern must match the whole value.
	 * Matching ignores case, as Elasticsearch's analysed titles do. Wildcards are * and ?.
	 * A regex is checked before it reaches PostgreSQL, where an invalid one would fail the
	 * whole search: anything PCRE rejects, or uses constructs PostgreSQL lacks, matches nothing.
	 * So does a field this platform has no column for.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private function patternGroup(mixed $group, bool $regex): array {
		$entries = [];
		$params = [];
		$pairs = [];
		foreach (is_array($group) ? $group : [] as $entry) {
			foreach (is_array($entry) ? $entry : [] as $field => $pattern) {
				$pairs[] = [(string)$field, $pattern];
			}
		}
		foreach ($pairs as [$field, $pattern]) {
			$column = self::FILTER_COLUMNS[$field] ?? null;
			$compiled = $column !== null ? self::compilePattern($pattern, $regex) : null;
			if ($column === null || $compiled === null) {
				continue;
			}
			[$op, $bound] = $compiled;
			$entries[] = "$column $op ?";
			$params[] = $bound;
		}
		return $entries === [] ? ['FALSE', []] : ['(' . implode(' OR ', $entries) . ')', $params];
	}

	/**
	 * Validate and convert a wildcard or regex pattern under A1's length, UTF-8/NUL, and portability bounds.
	 *
	 * @return array{0: '~*'|'ILIKE', 1: string}|null
	 */
	private static function compilePattern(mixed $pattern, bool $regex): ?array {
		if (!is_string($pattern) || $pattern === '' || strlen($pattern) > self::MAX_FILTER_PATTERN) {
			return null;
		}
		if (str_contains($pattern, "\0") || !mb_check_encoding($pattern, 'UTF-8')) {
			return null;
		}
		if ($regex) {
			if (!self::isPortableRegex($pattern)) {
				return null;
			}
			return ['~*', '^(?:' . $pattern . ')$'];
		}
		return ['ILIKE', strtr($pattern, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '*' => '%', '?' => '_'])];
	}

	/** A regex both PCRE and PostgreSQL accept with the same meaning, as far as can be told cheaply. */
	private static function isPortableRegex(string $pattern): bool {
		$len = strlen($pattern);
		$inClass = false;
		$classStart = false;
		/** @var list<array{lookahead: bool, branchCost: int, totalCost: int, lastAtomCost: int}> $stack */
		$stack = [['lookahead' => false, 'branchCost' => 0, 'totalCost' => 0, 'lastAtomCost' => 0]];
		$maxCost = 4096;

		for ($i = 0; $i < $len; $i++) {
			$ch = $pattern[$i];

			if ($ch === '\\') {
				$advance = self::portableEscapeLength($pattern, $i, $len);
				if ($advance === 0) {
					return false;
				}
				$i += $advance - 1;
				if ($inClass) {
					$classStart = false;
				} else {
					$topIdx = count($stack) - 1;
					$stack[$topIdx]['branchCost'] += 1;
					$stack[$topIdx]['lastAtomCost'] = 1;
				}
				continue;
			}

			if ($inClass) {
				if ($ch === '[' && $i + 1 < $len && ($pattern[$i + 1] === ':' || $pattern[$i + 1] === '.' || $pattern[$i + 1] === '=')) {
					$close = strpos($pattern, $pattern[$i + 1] . ']', $i + 2);
					if ($close === false) {
						return false;
					}
					$i = $close + 1;
					$classStart = false;
					continue;
				}
				if ($ch === ']' && !$classStart) {
					$inClass = false;
					$topIdx = count($stack) - 1;
					$stack[$topIdx]['branchCost'] += 1;
					$stack[$topIdx]['lastAtomCost'] = 1;
					continue;
				}
				$classStart = false;
				continue;
			}

			if ($ch === '[') {
				$inClass = true;
				$classStart = true;
				if ($i + 1 < $len && $pattern[$i + 1] === '^') {
					$i++;
				}
				continue;
			}

			if ($ch === '(') {
				if ($i + 1 >= $len) {
					return false;
				}
				$next = $pattern[$i + 1];
				if ($next === '*') {
					return false; // PCRE control verbs like (*UTF8), (*ANYCRLF), etc.
				}
				$isLookahead = false;
				if ($next === '?') {
					if ($i + 2 >= $len) {
						return false;
					}
					$type = $pattern[$i + 2];
					if ($type !== ':' && $type !== '=' && $type !== '!') {
						return false;
					}
					$isLookahead = ($type === '=' || $type === '!');
					$i += 2;
				}
				$stack[] = ['lookahead' => $isLookahead, 'branchCost' => 0, 'totalCost' => 0, 'lastAtomCost' => 0];
				continue;
			}

			if ($ch === '|') {
				$topIdx = count($stack) - 1;
				$stack[$topIdx]['totalCost'] += $stack[$topIdx]['branchCost'];
				if ($stack[$topIdx]['totalCost'] > $maxCost) {
					return false;
				}
				$stack[$topIdx]['branchCost'] = 0;
				$stack[$topIdx]['lastAtomCost'] = 0;
				continue;
			}

			if ($ch === ')') {
				if (count($stack) <= 1) {
					return false;
				}
				$frame = array_pop($stack);
				$groupCost = max(1, $frame['totalCost'] + $frame['branchCost']);
				if ($groupCost > $maxCost) {
					return false;
				}
				if ($frame['lookahead'] && $i + 1 < $len) {
					$after = $pattern[$i + 1];
					if ($after === '*' || $after === '+' || $after === '?') {
						return false;
					}
					if ($after === '{' && preg_match('/^\\{(?:\\d+(?:,\\d*)?|,\\d+)\\}/', substr($pattern, $i + 1)) === 1) {
						return false;
					}
				}
				$topIdx = count($stack) - 1;
				$stack[$topIdx]['branchCost'] += $groupCost;
				$stack[$topIdx]['lastAtomCost'] = $groupCost;
				continue;
			}

			if ($ch === '*' || $ch === '+' || $ch === '?') {
				if ($i + 1 < $len) {
					$mod = $pattern[$i + 1];
					if ($mod === '+') {
						return false;
					}
					if ($mod === '?') {
						$i++;
					}
				}
				continue;
			}

			if ($ch === '{' && preg_match('/^\\{(\\d+)?(?:,(\\d*))?\\}/', substr($pattern, $i), $m) === 1) {
				$n1 = isset($m[1]) && $m[1] !== '' ? $m[1] : null;
				$n2 = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
				if ($n1 !== null || $n2 !== null) {
					if ($n1 !== null && (strlen($n1) > 3 || (int)$n1 > 255)) {
						return false;
					}
					if ($n2 !== null && (strlen($n2) > 3 || (int)$n2 > 255)) {
						return false;
					}
					$repeat = max(1, (int)($n2 ?? $n1));
					$topIdx = count($stack) - 1;
					$atomCost = max(1, $stack[$topIdx]['lastAtomCost']);
					$newAtomCost = $atomCost * $repeat;
					$stack[$topIdx]['branchCost'] += $newAtomCost - $atomCost;
					$stack[$topIdx]['lastAtomCost'] = $newAtomCost;
					if ($stack[$topIdx]['totalCost'] + $stack[$topIdx]['branchCost'] > $maxCost) {
						return false;
					}
					$i += strlen($m[0]) - 1;
					if ($i + 1 < $len) {
						$mod = $pattern[$i + 1];
						if ($mod === '+') {
							return false;
						}
						if ($mod === '?') {
							$i++;
						}
					}
					continue;
				}
			}

			$topIdx = count($stack) - 1;
			$stack[$topIdx]['branchCost'] += 1;
			$stack[$topIdx]['lastAtomCost'] = 1;
			if ($stack[$topIdx]['totalCost'] + $stack[$topIdx]['branchCost'] > $maxCost) {
				return false;
			}
		}

		if ($inClass || count($stack) !== 1) {
			return false;
		}
		if ($stack[0]['totalCost'] + $stack[0]['branchCost'] > $maxCost) {
			return false;
		}
		$escaped = (string)preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\//', '$1\\/', $pattern);
		return @preg_match('/' . $escaped . '/u', '') !== false;
	}

	/** Byte length of an escape sequence at $offset, or 0 when unportable/invalid. */
	private static function portableEscapeLength(string $pattern, int $offset, int $len): int {
		if ($offset + 1 >= $len) {
			return 0;
		}
		$next = $pattern[$offset + 1];
		if (ord($next) >= 0x80 || ($next >= '0' && $next <= '9')) {
			return 0;
		}
		if (($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z')) {
			if ($next === 'x') {
				if ($offset + 3 < $len && ctype_xdigit($pattern[$offset + 2]) && ctype_xdigit($pattern[$offset + 3])) {
					return 4;
				}
				return 0;
			}
			return match ($next) {
				'd', 'D', 's', 'S', 'w', 'W', 'n', 'r', 't', 'f', 'a', 'e' => 2,
				default => 0,
			};
		}
		return 2;
	}

	/**
	 * Fields a search is limited to ("search in: file name / content"), as tsvector weight labels:
	 * null for no limit, '' when none of them is indexed by this platform.
	 *
	 * @param array<array-key, mixed> $fields
	 */
	private function limitWeights(array $fields): ?string {
		if ($fields === []) {
			return null;
		}
		$weights = [];
		foreach ($fields as $field) {
			$field = (string)$field;
			// Comments and other parts arrive as "parts.comments".
			$key = str_starts_with($field, 'parts.') ? 'parts' : $field;
			if (isset(self::FIELD_WEIGHTS[$key])) {
				$weights[self::FIELD_WEIGHTS[$key]] = true;
			}
		}
		ksort($weights);
		return implode('', array_keys($weights));
	}

	/**
	 * One branch per configured language, each restricted to the documents indexed in it,
	 * so a document is matched, ranked and excerpted with its own language's stemming.
	 * Documents indexed in a language no longer configured go with the fallback (first)
	 * language, as they did before multi-language support. Every branch keeps
	 * `d.tsv @@ …`, so PostgreSQL still answers each one from the GIN index.
	 *
	 * @param non-empty-array<string, ParsedQuery> $queries language => compiled query, fallback first
	 * @param list<string> $filterParams
	 * @param bool $filtersFirst start from the filters rather than the full-text index (see startFromFilters())
	 * @param list<string> $excerptWords the search words as typed, lowercased, to find the excerpt window
	 * @return list<array<string, mixed>>
	 */
	private function fullTextSearch(array $queries, string $filterSql, array $filterParams, bool $filtersFirst, int $limit, int $offset, array $excerptWords = []): array {
		$t = $this->schemaService->getQualifiedTableName();
		$conditions = self::languageConditions(array_keys($queries));

		$rankColumns = [];
		$rankParams = [];
		$match = [];
		$matchParams = [];
		$all = [];
		$allParams = [];
		$rankCase = [];
		$rankCaseParams = [];
		foreach (array_values($queries) as $i => $query) {
			[$condition, $conditionParams] = $conditions[$i];
			$rankColumns[] = "{$query->rankSql} AS r$i";
			array_push($rankParams, ...$query->rankParams);
			$match[] = "($condition AND {$query->sql})";
			array_push($matchParams, ...$conditionParams, ...$query->params);
			$all[] = "($condition AND {$query->allSql})";
			array_push($allParams, ...$conditionParams, ...$query->allParams);
			if ($i > 0) {
				$rankCase[] = "WHEN $condition THEN q.r$i";
				array_push($rankCaseParams, ...$conditionParams);
			}
		}
		$rankQuery = $rankCase === [] ? 'q.r0' : 'CASE ' . implode(' ', $rankCase) . ' ELSE q.r0 END';
		$matchSql = count($match) === 1 ? $match[0] : '(' . implode(' OR ', $match) . ')';
		if ($filtersFirst) {
			// Starting from the filters: keep the GIN index out of it (see startFromFilters()).
			$matchSql = "($matchSql) IS TRUE";
		}
		$allSql = count($all) === 1 ? $all[0] : '(' . implode(' OR ', $all) . ')';

		$excerptSql = $this->excerptSource();
		$headlineSql = $this->headlineSql();
		[$rankSql, $rankSqlParams, $termColumns, $termParams, $fence]
			= $this->idfRanking($queries, $conditions, $filterSql, $filterParams, $filtersFirst, $rankQuery, $rankCaseParams);
		$rankColumnsSql = implode(', ', [...$rankColumns, ...$termColumns]);

		if ($fence) {
			// IDF ranking calls ts_rank_cd once per term. Costed into the scan, that extra work
			// makes PostgreSQL prefer a parallel sequential scan even for a few thousand matches,
			// and testing every row's tsvector is slower than the bitmap scan of the GIN index it
			// used before. OFFSET 0 keeps the matching scan planned on its own, as before, and
			// ranks the matches above it. See idfRanking() for when this applies.
			$sql = <<<SQL
				WITH q AS (SELECT $rankColumnsSql)
				SELECT m.document_id, m.title, m.source, m.hash, m.modified_time, m.rank, m.total,
					   h.excerpt
				FROM (
					SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config, d.content, d.parts_text, d.all_match,
						   $rankQuery AS rank_query,
						   $rankSql AS rank,
						   count(*) OVER () AS total
					FROM (
						SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config, d.tsv,
							   d.content, d.parts_text,
							   $allSql AS all_match
						FROM $t d
						WHERE $matchSql AND $filterSql
						OFFSET 0
					) d, q
					ORDER BY d.all_match DESC, rank DESC, d.modified_time DESC, d.id
					LIMIT ? OFFSET ?
				) m
				CROSS JOIN LATERAL ($excerptSql) e
				CROSS JOIN LATERAL ($headlineSql) h
				ORDER BY m.all_match DESC, m.rank DESC, m.modified_time DESC, m.id
				SQL;
			$params = array_merge(
				$rankParams, $termParams,
				$rankCaseParams, $rankSqlParams, $allParams, $matchParams,
				$filterParams, [(string)$limit, (string)$offset],
				[PgArray::toLiteral($excerptWords), self::HEADLINE_OPTIONS, self::HEADLINE_OPTIONS]
			);
		} else {
			// The inner query filters, ranks and pages; ts_headline (expensive) only runs on the page.
			// Documents containing every search term come first, then partial (OR) matches.
			$sql = <<<SQL
				WITH q AS (SELECT $rankColumnsSql)
				SELECT m.document_id, m.title, m.source, m.hash, m.modified_time, m.rank, m.total,
					   h.excerpt
				FROM (
					SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config,
						   d.content, d.parts_text,
						   $rankQuery AS rank_query,
						   $allSql AS all_match,
						   $rankSql AS rank,
						   count(*) OVER () AS total
					FROM $t d, q
					WHERE $matchSql AND $filterSql
					ORDER BY all_match DESC, rank DESC, d.modified_time DESC, d.id
					LIMIT ? OFFSET ?
				) m
				CROSS JOIN LATERAL ($excerptSql) e
				CROSS JOIN LATERAL ($headlineSql) h
				ORDER BY m.all_match DESC, m.rank DESC, m.modified_time DESC, m.id
				SQL;

			$params = array_merge(
				$rankParams, $termParams,
				$rankCaseParams, $allParams, $rankSqlParams, $matchParams,
				$filterParams, [(string)$limit, (string)$offset],
				[PgArray::toLiteral($excerptWords), self::HEADLINE_OPTIONS, self::HEADLINE_OPTIONS]
			);
		}
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * The text ts_headline excerpts for a result row of the page (alias m): the whole body when
	 * it is short, otherwise EXCERPT_WINDOW characters starting a little before the first match.
	 *
	 * The first match is the earliest position of any search word as typed, or of any lexeme of
	 * the row's own tsquery (stems, accent variants, typo corrections), in the lowercased body,
	 * preferring a word start. Stems usually begin their surface forms ("rapor" in "raporları").
	 * When nothing is found, the window starts at the beginning, which is what ts_headline
	 * shows anyway when it finds no match. Text past the window is not used for fragments.
	 *
	 * One parameter: the search words, as a text[] literal.
	 */
	private function excerptSource(): string {
		$window = self::EXCERPT_WINDOW;
		$lead = self::EXCERPT_LEAD;
		$lowered = 'lower(' . $this->schemaService->normalized('b.text') . ')';
		// querytree() prints the lexemes quoted, with quotes inside doubled: 'o''brien':*
		$lexemes = "SELECT replace((regexp_matches(querytree(m.rank_query)::text, '''((?:[^'']|'''')+)''', 'g'))[1], '''''', '''')";
		// Content and parts_text stay separate until the page-level lateral so inner queries never
		// detoast non-page rows. A long body is detoasted once into b.text via OFFSET 0.
		$joined = "CASE WHEN m.content = '' THEN m.parts_text WHEN m.parts_text = '' THEN m.content ELSE m.content || ' ' || m.parts_text END";
		return <<<SQL
			SELECT CASE WHEN b.text IS NULL THEN ($joined)
			            ELSE substr(b.text, greatest(1, coalesce((
			                SELECT min(p.pos) FROM (
			                    SELECT CASE WHEN starts_with(l.lb, w.word) THEN 1
			                                ELSE coalesce(nullif(strpos(l.lb, ' ' || w.word), 0) + 1, strpos(l.lb, w.word)) END AS pos
			                    FROM (SELECT $lowered AS lb OFFSET 0) l,
			                         unnest(CAST(? AS text[]) || ARRAY($lexemes)) AS w(word)
			                    WHERE w.word <> ''
			                ) p WHERE p.pos > 0
			            ), 1) - $lead), $window) END AS source
			FROM (SELECT CASE WHEN octet_length(m.content) + octet_length(m.parts_text) > $window THEN ($joined) || '' END AS text OFFSET 0) b
			SQL;
	}

	/**
	 * The excerpt of a result row (alias m) from its excerpt source (alias e), as a lateral
	 * subquery. Two parameters: the headline options, twice.
	 *
	 * Turkish rows are indexed with I mapped to ı and İ to i (see SchemaService::normalized()),
	 * but ts_headline lowercases with the database locale, so uppercase words like ISPARTA would
	 * never match the query and their excerpts would miss them. The mapped text is excerpted
	 * instead, and each fragment is copied back from the original text at the same position:
	 * the mapping replaces one character with one character, so positions agree, and users see
	 * the text as written.
	 */
	private function headlineSql(): string {
		$delimiter = self::FRAGMENT_DELIMITER;
		return <<<SQL
			SELECT CASE WHEN m.config = 'turkish' THEN array_to_string(ARRAY(
			           SELECT CASE WHEN strpos(n.text, f.fragment) > 0
			                       THEN substr(e.source, strpos(n.text, f.fragment), length(f.fragment))
			                       ELSE f.fragment END
			           FROM (SELECT translate(e.source, 'Iİ', 'ıi') AS text) n,
			                unnest(string_to_array(ts_headline(m.config::pg_catalog.regconfig, n.text, m.rank_query, ?), '$delimiter')) WITH ORDINALITY AS f(fragment, i)
			           ORDER BY f.i
			       ), '$delimiter')
			       ELSE ts_headline(m.config::pg_catalog.regconfig, e.source, m.rank_query, ?) END AS excerpt
			SQL;
	}

	/**
	 * How matches are scored.
	 *
	 * One positive term: ts_rank_cd against it, as always. Several: ts_rank_cd has no notion
	 * of how rare a word is, so a common word ("rapor", in thousands of documents) mentioned
	 * twice would outweigh a rare one ("kırklareli", in three) mentioned once. Each term is
	 * therefore scored on its own and weighted by its inverse document frequency, BM25's
	 * idf = ln(1 + (N - df + 0.5) / (df + 0.5)):
	 *
	 *   rank = Σ idf_i · ts_rank_cd(tsv, term_i, 32) / Σ idf_i
	 *
	 * Normalisation 32 turns each term's weighted occurrence count c into c / (c + 1), a
	 * saturating term frequency like BM25's, and the weighted mean keeps rank within 0–1.
	 * Documents containing every term still come first (all_match); this orders the rest.
	 *
	 * The weights come from documentFrequencies() and are bound into the search as numbers.
	 * Computing them in the same statement would make it reference the q CTE twice, which
	 * materialises it and rules out the parallel scan PostgreSQL otherwise uses for large
	 * result sets: three times slower on a word found in most documents.
	 *
	 * Each extra term costs one more ts_rank_cd per matching row. When the terms' frequencies
	 * add up to less than a quarter of the index, the matching scan is fenced off (see
	 * fullTextSearch()) so it keeps its bitmap index scan; above that, PostgreSQL would scan the
	 * table in parallel anyway, as it did before, and leaving the query unfenced lets the
	 * parallel workers do the ranking.
	 *
	 * Returns the rank expression and its params, the per-term columns for the q CTE and their
	 * params, and whether to fence the matching scan.
	 *
	 * @param non-empty-array<string, ParsedQuery> $queries
	 * @param list<array{0: string, 1: list<string>}> $conditions per language, as in fullTextSearch()
	 * @param list<string> $filterParams
	 * @param list<string> $rankCaseParams
	 * @return array{0: string, 1: list<string>, 2: list<string>, 3: list<string>, 4: bool}
	 */
	private function idfRanking(array $queries, array $conditions, string $filterSql, array $filterParams, bool $filtersFirst, string $rankQuery, array $rankCaseParams): array {
		$plain = ["ts_rank_cd(d.tsv, $rankQuery, 32)", $rankCaseParams, [], [], false];

		$queryList = array_values($queries);
		$count = count($queryList[0]->terms);
		if ($count < 2 || $count > self::MAX_IDF_TERMS) {
			return $plain;
		}
		foreach ($queryList as $query) {
			if (count($query->terms) !== $count) {
				return $plain; // languages tokenize the search the same way, but never rely on it
			}
		}

		[$frequencies, $total] = $this->documentFrequencies($queryList, $conditions, $filterSql, $filterParams, $filtersFirst);
		$weights = [];
		foreach ($frequencies as $j => $df) {
			if ($df > 0) {
				// max(N, df) keeps the logarithm defined when statistics are stale.
				$n = max($total, $df);
				$weights[$j] = log(1.0 + ((float)$n - (float)$df + 0.5) / ((float)$df + 0.5));
			}
		}
		if (count($weights) < 2) {
			// Nothing to weigh: at most one term occurs in a visible document.
			return $plain;
		}

		$termColumns = [];
		$termParams = [];
		foreach ($queryList as $i => $query) {
			foreach ($query->terms as $j => [$termSql, $params]) {
				if (isset($weights[$j])) {
					$termColumns[] = "$termSql AS t{$i}_$j";
					array_push($termParams, ...$params);
				}
			}
		}

		$weighted = [];
		$weightedParams = [];
		foreach ($weights as $j => $weight) {
			// Term j's tsquery in the row's own language, the way $rankQuery picks the whole query's.
			$cases = [];
			foreach (array_slice($conditions, 1, null, true) as $i => [$condition, $_]) {
				$cases[] = "WHEN $condition THEN q.t{$i}_$j";
			}
			$term = $cases === [] ? "q.t0_$j" : 'CASE ' . implode(' ', $cases) . " ELSE q.t0_$j END";
			$weighted[] = "CAST(? AS float8) * ts_rank_cd(d.tsv, $term, 32)";
			array_push($weightedParams, self::number($weight), ...$rankCaseParams);
		}
		$weightedParams[] = self::number(array_sum($weights));

		return [
			'((' . implode(' + ', $weighted) . ') / CAST(? AS float8))',
			$weightedParams,
			$termColumns,
			$termParams,
			array_sum($frequencies) < self::FENCE_FRACTION * $total,
		];
	}

	/**
	 * For each positive term, how many documents match it alone, in their own language, among
	 * those the viewer can see in this provider (the search's own filters): frequencies never
	 * reveal anything about documents the viewer cannot open.
	 *
	 * Each term is counted by its own subquery, whose conditions the GIN indexes answer
	 * exactly, so rows are counted without reading their tsvector. Counting stops at
	 * EXACT_FREQUENCY_LIMIT matches; for a term that common, the planner's estimate is used
	 * instead. Its statistics track the most common lexemes, so the estimate is close for
	 * frequent words and the exact count is spent where it matters, on rare ones. A word in
	 * 90% of 100,000 documents would otherwise take most of a second to count.
	 *
	 * N, the collection size, is the size of the whole index: the planner's estimate for large
	 * tables (counting every visible document on each keystroke would cost more than the
	 * search), exact below 10,000 rows. When df ≪ N, the difference between two terms' weights
	 * is ln(df_j / df_i) whatever N is, so the estimate barely matters.
	 *
	 * @param non-empty-list<ParsedQuery> $queries one per language, each with the same number of terms
	 * @param list<array{0: string, 1: list<string>}> $conditions per language
	 * @param list<string> $filterParams
	 * @param bool $filtersFirst count from the filters rather than the full-text index (see startFromFilters())
	 * @return array{0: list<int>, 1: float} document frequency per term, N
	 */
	private function documentFrequencies(array $queries, array $conditions, string $filterSql, array $filterParams, bool $filtersFirst): array {
		$t = $this->schemaService->getQualifiedTableName();
		$limit = self::EXACT_FREQUENCY_LIMIT;
		$columns = [];
		$params = [];
		$terms = [];
		foreach (array_keys($queries[0]->terms) as $j) {
			$branches = [];
			$termParams = [];
			foreach ($queries as $i => $query) {
				[$condition, $conditionParams] = $conditions[$i];
				[$termSql, $sqlParams] = $query->terms[$j];
				$branches[] = "($condition AND d.tsv @@ $termSql)";
				array_push($termParams, ...$conditionParams, ...$sqlParams);
			}
			$termWhere = '(' . implode(' OR ', $branches) . ')' . ($filtersFirst ? ' IS TRUE' : '');
			$terms[$j] = ["SELECT 1 FROM $t d WHERE $termWhere AND $filterSql", [...$termParams, ...$filterParams]];
			$columns[] = "(SELECT count(*) FROM ({$terms[$j][0]} LIMIT $limit) x)";
			array_push($params, ...$terms[$j][1]);
		}
		$columns[] = "(SELECT CASE WHEN c.reltuples >= 10000 THEN c.reltuples::bigint ELSE (SELECT count(*) FROM $t) END
			FROM pg_catalog.pg_class c WHERE c.oid = pg_catalog.to_regclass(?))";
		$params[] = $t;

		$result = $this->db->executeQuery('SELECT ' . implode(', ', $columns), $params);
		$row = $result->fetch(\PDO::FETCH_NUM);
		$result->closeCursor();
		$row = is_array($row) ? $row : [];

		$total = (float)array_pop($row);
		$frequencies = array_map('intval', $row);
		foreach ($frequencies as $j => $df) {
			if ($df >= $limit) {
				$frequencies[$j] = max($limit, $this->estimateRows(...$terms[$j]));
			}
		}
		return [$frequencies, $total];
	}

	/**
	 * The planner's row estimate for a query, without running it.
	 *
	 * @param list<string> $params
	 */
	private function estimateRows(string $sql, array $params): int {
		$result = $this->db->executeQuery('EXPLAIN (FORMAT JSON) ' . $sql, $params);
		$plan = json_decode((string)$result->fetchOne(), true);
		$result->closeCursor();
		return is_array($plan) ? (int)round((float)($plan[0]['Plan']['Plan Rows'] ?? 0)) : 0;
	}

	/** A float as a SQL numeric literal, independent of the PHP locale. */
	private static function number(float $value): string {
		return sprintf('%.12F', $value);
	}

	/**
	 * @param non-empty-array<string, ParsedQuery> $queries
	 * @param list<string> $filterParams
	 */
	private function fullTextCount(array $queries, string $filterSql, array $filterParams, bool $filtersFirst): int {
		$t = $this->schemaService->getQualifiedTableName();
		[$matchSql, $matchParams] = $this->matchCondition($queries);
		if ($filtersFirst) {
			$matchSql = "($matchSql) IS TRUE";
		}
		$result = $this->db->executeQuery(
			"SELECT count(*) FROM $t d WHERE $matchSql AND $filterSql",
			[...$matchParams, ...$filterParams]
		);
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @return list<string>
	 */
	private static function validFilterStrings(array $values): array {
		$valid = [];
		foreach ($values as $value) {
			$str = (string)$value;
			if (!str_contains($str, "\0") && mb_check_encoding($str, 'UTF-8')) {
				$valid[] = $str;
			}
		}
		return $valid;
	}

	/**
	 * Restricts the title fallback to rows whose language treats at least one search word as
	 * a non-stopword; PostgreSQL constant-folds numnode(...) while planning.
	 *
	 * @param non-empty-array<string, ParsedQuery> $queries
	 * @return array{0: string, 1: list<string>}
	 */
	private function nonStopwordCondition(array $queries): array {
		$conditions = self::languageConditions(array_keys($queries));
		$branches = [];
		$params = [];
		foreach (array_values($queries) as $i => $query) {
			[$condition, $conditionParams] = $conditions[$i];
			$branches[] = "($condition AND numnode({$query->rankSql}) > 0)";
			array_push($params, ...$conditionParams, ...$query->rankParams);
		}
		return [count($branches) === 1 ? $branches[0] : '(' . implode(' OR ', $branches) . ')', $params];
	}

	/**
	 * @param list<string> $nonStopParams
	 * @param list<string> $filterParams
	 * @return list<array<string, mixed>>
	 */
	private function titleSimilaritySearch(string $text, string $nonStopSql, array $nonStopParams, string $filterSql, array $filterParams, int $limit, int $offset = 0): array {
		$t = $this->schemaService->getQualifiedTableName();
		$ts = $this->schemaService->getTrigramSchema();
		$wordSim = $ts !== null ? "{$ts}.word_similarity" : 'word_similarity';
		$wordOp = $ts !== null ? "OPERATOR({$ts}.<%)" : '<%';
		$joined = "CASE WHEN d.content = '' THEN d.parts_text WHEN d.parts_text = '' THEN d.content ELSE d.content || ' ' || d.parts_text END";
		$sql = <<<SQL
			SELECT d.document_id, d.title, d.source, d.hash, d.modified_time,
				   {$wordSim}(?, d.title_search) AS rank,
				   count(*) OVER () AS total,
				   left($joined, 300) AS excerpt
			FROM $t d
			WHERE ? {$wordOp} d.title_search AND $nonStopSql AND $filterSql
			ORDER BY rank DESC, d.modified_time DESC, d.id
			LIMIT ? OFFSET ?
			SQL;

		$text = mb_strtolower($text, 'UTF-8');
		$params = array_merge([$text, $text], $nonStopParams, $filterParams, [(string)$limit, (string)$offset]);
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param list<string> $nonStopParams
	 * @param list<string> $filterParams
	 */
	private function titleSimilarityCount(string $text, string $nonStopSql, array $nonStopParams, string $filterSql, array $filterParams): int {
		$t = $this->schemaService->getQualifiedTableName();
		$ts = $this->schemaService->getTrigramSchema();
		$wordOp = $ts !== null ? "OPERATOR({$ts}.<%)" : '<%';
		$text = mb_strtolower($text, 'UTF-8');
		$result = $this->db->executeQuery(
			"SELECT count(*) FROM $t d WHERE ? {$wordOp} d.title_search AND $nonStopSql AND $filterSql",
			array_merge([$text], $nonStopParams, $filterParams)
		);
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function fuzzyAvailable(): bool {
		return $this->configService->useTrigram() && $this->schemaService->hasTrigram();
	}

	private function toResultDocument(string $providerId, array $row, string $score, string $viewerId): IIndexDocument {
		$access = new DocumentAccess();
		$access->setViewerId($viewerId);

		$document = new IndexDocument($providerId, (string)$row['document_id']);
		$document->setAccess($access);
		$document->setTitle((string)$row['title']);
		$document->setSource((string)$row['source']);
		$document->setHash((string)$row['hash']);
		$document->setModifiedTime((int)$row['modified_time']);
		$document->setScore($score);

		$excerpts = [];
		foreach (explode(self::FRAGMENT_DELIMITER, (string)$row['excerpt']) as $fragment) {
			$fragment = trim($fragment);
			if ($fragment !== '') {
				$excerpts[] = ['source' => 'content', 'excerpt' => $fragment];
			}
		}
		$document->setExcerpts($excerpts);

		return $document;
	}

	private function decode(mixed $json): array {
		if (!is_string($json) || $json === '') {
			return [];
		}
		$value = json_decode($json, true);
		return is_array($value) ? $value : [];
	}
}
