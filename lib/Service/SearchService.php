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
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IDBConnection;

class SearchService {

	private const FRAGMENT_DELIMITER = '|||';
	/** Searches with more positive terms than this are ranked as one query, without IDF weights. */
	private const MAX_IDF_TERMS = 8;
	/** Below this share of the index, matched by the terms together, the matching scan is fenced (see idfRanking()). */
	private const FENCE_FRACTION = 0.25;
	/** Terms matching more documents than this get the planner's estimate instead of an exact count. */
	private const EXACT_FREQUENCY_LIMIT = 10000;
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
		$start = microtime(true);
		$request = $result->getRequest();
		$providerId = $result->getProvider()->getId();

		$viewerTokens = AccessTokens::forViewer($access);
		$languages = $this->configService->getLanguages();
		$search = $request->getSearch();

		$alternatives = [];
		if ($this->configService->useTypoCorrection()) {
			// Words as each language tokenizes them (Turkish lowercases I to ı), looked up once.
			$words = [];
			foreach ($languages as $language) {
				array_push($words, ...$this->queryBuilder->searchWords($search, $language));
			}
			$alternatives = $this->vocabulary->alternatives(array_values(array_unique($words)), $this->fuzzyAvailable());
		}

		/** @var array<string, ParsedQuery> $queries */
		$queries = [];
		foreach ($languages as $language) {
			$query = $this->queryBuilder->build($search, $language, $this->fuzzyAvailable(), $alternatives);
			if ($query === null) {
				break;
			}
			$queries[$language] = $query;
		}
		if ($viewerTokens === [] || count($queries) !== count($languages)) {
			$result->setTotal(0);
			return;
		}
		$query = $queries[$languages[0]];

		$size = max(1, min($request->getSize(), $this->configService->getMaxResults()));
		$page = max(1, $request->getPage());
		[$filterSql, $filterParams] = $this->filters($request, $providerId, $viewerTokens);

		$rows = $this->fullTextSearch($queries, $filterSql, $filterParams, $size, ($page - 1) * $size);
		if ($rows === [] && $page === 1 && $this->fuzzyAvailable() && $query->plainText !== '') {
			// Nothing matched exactly: fall back to typo-tolerant matching on titles ("rapr" -> "rapor").
			$rows = $this->titleSimilaritySearch($query->plainText, $filterSql, $filterParams, $size);
		}

		$maxScore = 0;
		foreach ($rows as $row) {
			$score = round((float)$row['rank'] * 100, 2);
			$maxScore = max($maxScore, (int)ceil($score));
			$result->addDocument($this->toResultDocument($providerId, $row, (string)$score, $access->getViewerId()));
		}

		$result->setTotal($rows === [] ? 0 : (int)$rows[0]['total']);
		$result->setMaxScore($maxScore);
		$result->setTime((int)round((microtime(true) - $start) * 1000));
		$result->setTimedOut(false);
	}

	/**
	 * @throws DocumentNotFoundException
	 */
	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		$result = $this->db->executeQuery(
			'SELECT owner_id, array_to_json(access) AS access, array_to_json(links) AS links,
					array_to_json(tags) AS tags, array_to_json(metatags) AS metatags, array_to_json(subtags) AS subtags,
					source, hash, modified_time, title, parts, content, info
			 FROM ' . $this->schemaService->getTableName() . ' WHERE provider_id = ? AND document_id = ?',
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
	 * @return array{0: string, 1: list<string>}
	 */
	private function filters(ISearchRequest $request, string $providerId, array $viewerTokens): array {
		$sql = 'd.provider_id = ? AND d.access && CAST(? AS text[])';
		$params = [$providerId, PgArray::toLiteral($viewerTokens)];

		$metaTags = array_values(array_map('strval', $request->getMetaTags()));
		if ($metaTags !== []) {
			// any of the requested meta tags
			$sql .= ' AND d.metatags && CAST(? AS text[])';
			$params[] = PgArray::toLiteral($metaTags);
		}

		$subTags = array_values(array_map('strval', $request->getSubTags(true)));
		if ($subTags !== []) {
			// all of the requested sub tags
			$sql .= ' AND d.subtags @> CAST(? AS text[])';
			$params[] = PgArray::toLiteral($subTags);
		}

		$since = (int)$request->getOption('since');
		if ($since > 0) {
			$sql .= ' AND d.modified_time >= ?';
			$params[] = (string)$since;
		}

		return [$sql, $params];
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
	 * @return list<array<string, mixed>>
	 */
	private function fullTextSearch(array $queries, string $filterSql, array $filterParams, int $limit, int $offset): array {
		$t = $this->schemaService->getTableName();
		$languages = array_keys($queries);
		$others = array_slice($languages, 1);

		// Which rows a branch applies to: one condition per language, in $languages order.
		$conditions = [];
		foreach ($languages as $i => $language) {
			$conditions[] = $i === 0
				? ($others === [] ? ['TRUE', []] : ['d.config <> ALL (CAST(? AS regconfig[]))', ['{' . implode(',', $others) . '}']])
				: ['d.config = CAST(? AS regconfig)', [$language]];
		}

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
		$allSql = count($all) === 1 ? $all[0] : '(' . implode(' OR ', $all) . ')';

		[$rankSql, $rankSqlParams, $termColumns, $termParams, $fence]
			= $this->idfRanking($queries, $conditions, $filterSql, $filterParams, $rankQuery, $rankCaseParams);
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
					   ts_headline(m.config, m.body, m.rank_query, ?) AS excerpt
				FROM (
					SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config, d.body, d.all_match,
						   $rankQuery AS rank_query,
						   $rankSql AS rank,
						   count(*) OVER () AS total
					FROM (
						SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config, d.tsv,
							   CASE WHEN d.content <> '' THEN d.content ELSE d.parts_text END AS body,
							   $allSql AS all_match
						FROM $t d
						WHERE $matchSql AND $filterSql
						OFFSET 0
					) d, q
					ORDER BY d.all_match DESC, rank DESC, d.modified_time DESC, d.id
					LIMIT ? OFFSET ?
				) m
				ORDER BY m.all_match DESC, m.rank DESC, m.modified_time DESC, m.id
				SQL;
			$params = array_merge(
				$rankParams, $termParams, [self::HEADLINE_OPTIONS],
				$rankCaseParams, $rankSqlParams, $allParams, $matchParams,
				$filterParams, [(string)$limit, (string)$offset]
			);
		} else {
			// The inner query filters, ranks and pages; ts_headline (expensive) only runs on the page.
			// Documents containing every search term come first, then partial (OR) matches.
			$sql = <<<SQL
				WITH q AS (SELECT $rankColumnsSql)
				SELECT m.document_id, m.title, m.source, m.hash, m.modified_time, m.rank, m.total,
					   ts_headline(m.config, m.body, m.rank_query, ?) AS excerpt
				FROM (
					SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config,
						   CASE WHEN d.content <> '' THEN d.content ELSE d.parts_text END AS body,
						   $rankQuery AS rank_query,
						   $allSql AS all_match,
						   $rankSql AS rank,
						   count(*) OVER () AS total
					FROM $t d, q
					WHERE $matchSql AND $filterSql
					ORDER BY all_match DESC, rank DESC, d.modified_time DESC, d.id
					LIMIT ? OFFSET ?
				) m
				ORDER BY m.all_match DESC, m.rank DESC, m.modified_time DESC, m.id
				SQL;

			$params = array_merge(
				$rankParams, $termParams, [self::HEADLINE_OPTIONS],
				$rankCaseParams, $allParams, $rankSqlParams, $matchParams,
				$filterParams, [(string)$limit, (string)$offset]
			);
		}
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
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
	private function idfRanking(array $queries, array $conditions, string $filterSql, array $filterParams, string $rankQuery, array $rankCaseParams): array {
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

		[$frequencies, $total] = $this->documentFrequencies($queryList, $conditions, $filterSql, $filterParams);
		$weights = [];
		foreach ($frequencies as $j => $df) {
			if ($df > 0) {
				// max(N, df) keeps the logarithm defined when statistics are stale.
				$n = max($total, $df);
				$weights[$j] = log(1 + ($n - $df + 0.5) / ($df + 0.5));
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
	 * @return array{0: list<int>, 1: float} document frequency per term, N
	 */
	private function documentFrequencies(array $queries, array $conditions, string $filterSql, array $filterParams): array {
		$t = $this->schemaService->getTableName();
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
			$terms[$j] = ["SELECT 1 FROM $t d WHERE (" . implode(' OR ', $branches) . ") AND $filterSql", [...$termParams, ...$filterParams]];
			$columns[] = "(SELECT count(*) FROM ({$terms[$j][0]} LIMIT $limit) x)";
			array_push($params, ...$terms[$j][1]);
		}
		$columns[] = "(SELECT CASE WHEN c.reltuples >= 10000 THEN c.reltuples::bigint ELSE (SELECT count(*) FROM $t) END
			FROM pg_class c WHERE c.oid = to_regclass(?))";
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
	 * @param list<string> $filterParams
	 * @return list<array<string, mixed>>
	 */
	private function titleSimilaritySearch(string $text, string $filterSql, array $filterParams, int $limit): array {
		$t = $this->schemaService->getTableName();
		$sql = <<<SQL
			SELECT d.document_id, d.title, d.source, d.hash, d.modified_time,
				   word_similarity(?, d.title_search) AS rank,
				   count(*) OVER () AS total,
				   left(CASE WHEN d.content <> '' THEN d.content ELSE d.parts_text END, 300) AS excerpt
			FROM $t d
			WHERE ? <% d.title_search AND $filterSql
			ORDER BY rank DESC, d.modified_time DESC, d.id
			LIMIT ?
			SQL;

		$text = mb_strtolower($text, 'UTF-8');
		$params = array_merge([$text, $text], $filterParams, [(string)$limit]);
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
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
