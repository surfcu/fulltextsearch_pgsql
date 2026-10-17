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
		$weights = $this->limitWeights($request->getLimitFields());
		if ($weights === '') {
			// Limited to fields this platform does not index.
			$result->setTotal(0);
			return;
		}

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
			$query = $this->queryBuilder->build($search, $language, $this->fuzzyAvailable(), $alternatives, $weights);
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

		$excerptWords = [];
		foreach ($languages as $language) {
			array_push($excerptWords, ...$this->queryBuilder->searchWords($search, $language));
		}
		$rows = $this->fullTextSearch($queries, $filterSql, $filterParams, $size, ($page - 1) * $size, array_values(array_unique($excerptWords)));
		$titlesSearched = $weights === null || str_contains($weights, 'A');
		if ($rows === [] && $page === 1 && $titlesSearched && $this->fuzzyAvailable() && $query->plainText !== '') {
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

		// Each filter is a group of entries (field => pattern), any of which may match; every group
		// must match. Same semantics as the Elasticsearch platform. The Files app's "filter by
		// extension" arrives as one regex group with one entry: [['title' => '.*\.pdf']].
		foreach ($request->getWildcardFilters() as $group) {
			[$groupSql, $groupParams] = $this->patternGroup($group, false);
			$sql .= " AND $groupSql";
			array_push($params, ...$groupParams);
		}
		foreach ($request->getRegexFilters() as $group) {
			[$groupSql, $groupParams] = $this->patternGroup($group, true);
			$sql .= " AND $groupSql";
			array_push($params, ...$groupParams);
		}

		return [$sql, $params];
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
			if ($column === null || !is_string($pattern) || $pattern === '' || strlen($pattern) > self::MAX_FILTER_PATTERN) {
				continue;
			}
			if ($regex) {
				if (!self::isPortableRegex($pattern)) {
					continue;
				}
				$entries[] = "$column ~* ?";
				$params[] = '^(?:' . $pattern . ')$';
			} else {
				$entries[] = "$column ILIKE ?";
				$params[] = strtr($pattern, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '*' => '%', '?' => '_']);
			}
		}
		return $entries === [] ? ['FALSE', []] : ['(' . implode(' OR ', $entries) . ')', $params];
	}

	/** A regex both PCRE and PostgreSQL accept with the same meaning, as far as can be told cheaply. */
	private static function isPortableRegex(string $pattern): bool {
		/*
		 * Rejected: \K, \Q…\E, \R, \h, \v, \X, \A, \z, \Z, \G, and \b, \B (a backspace in PostgreSQL,
		 * not a word boundary) – missing or different there;
		 * groups starting with "(?" other than (?:, (?= and (?! (named and atomic groups, branch
		 * reset, inline flags); possessive quantifiers such as a++.
		 */
		if (preg_match('/\\\\[KXRhHvVQEGAzZbB]|\\(\\?[^:=!]|[*+?}]\\+/', $pattern)) {
			return false;
		}
		return @preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', '') !== false;
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
	 * @param list<string> $excerptWords the search words as typed, lowercased, to find the excerpt window
	 * @return list<array<string, mixed>>
	 */
	private function fullTextSearch(array $queries, string $filterSql, array $filterParams, int $limit, int $offset, array $excerptWords = []): array {
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

		$excerptSql = $this->excerptSource();
		$headlineSql = $this->headlineSql();
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
					   h.excerpt
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
		// A long body is detoasted once, into b.text: every function given the column itself
		// would decompress it again, about 3 ms per 500 KB. octet_length() reads the stored size
		// without decompressing; a body short in bytes is short in characters too. OFFSET 0 keeps
		// PostgreSQL from inlining the subqueries, which would repeat the work at every use.
		return <<<SQL
			SELECT CASE WHEN b.text IS NULL THEN m.body
			            ELSE substr(b.text, greatest(1, coalesce((
			                SELECT min(p.pos) FROM (
			                    SELECT CASE WHEN starts_with(l.lb, w.word) THEN 1
			                                ELSE coalesce(nullif(strpos(l.lb, ' ' || w.word), 0) + 1, strpos(l.lb, w.word)) END AS pos
			                    FROM (SELECT $lowered AS lb OFFSET 0) l,
			                         unnest(CAST(? AS text[]) || ARRAY($lexemes)) AS w(word)
			                    WHERE w.word <> ''
			                ) p WHERE p.pos > 0
			            ), 1) - $lead), $window) END AS source
			FROM (SELECT CASE WHEN octet_length(m.body) > $window THEN m.body || '' END AS text OFFSET 0) b
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
			SELECT CASE WHEN m.config = 'turkish'::regconfig THEN array_to_string(ARRAY(
			           SELECT CASE WHEN strpos(n.text, f.fragment) > 0
			                       THEN substr(e.source, strpos(n.text, f.fragment), length(f.fragment))
			                       ELSE f.fragment END
			           FROM (SELECT translate(e.source, 'Iİ', 'ıi') AS text) n,
			                unnest(string_to_array(ts_headline(m.config, n.text, m.rank_query, ?), '$delimiter')) WITH ORDINALITY AS f(fragment, i)
			           ORDER BY f.i
			       ), '$delimiter')
			       ELSE ts_headline(m.config, e.source, m.rank_query, ?) END AS excerpt
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
