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
	private const HEADLINE_OPTIONS = 'StartSel="",StopSel="",MaxFragments=3,MaxWords=30,MinWords=12,FragmentDelimiter="' . self::FRAGMENT_DELIMITER . '"';

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
		private ConfigService $configService,
		private TsQueryBuilder $queryBuilder,
	) {
	}

	public function searchRequest(ISearchResult $result, IDocumentAccess $access): void {
		$start = microtime(true);
		$request = $result->getRequest();
		$providerId = $result->getProvider()->getId();

		$viewerTokens = AccessTokens::forViewer($access);
		$query = $this->queryBuilder->build($request->getSearch(), $this->configService->getLanguage(), $this->fuzzyAvailable());
		if ($viewerTokens === [] || $query === null) {
			$result->setTotal(0);
			return;
		}

		$size = max(1, min($request->getSize(), $this->configService->getMaxResults()));
		$page = max(1, $request->getPage());
		[$filterSql, $filterParams] = $this->filters($request, $providerId, $viewerTokens);

		$rows = $this->fullTextSearch($query, $filterSql, $filterParams, $size, ($page - 1) * $size);
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
	 * @param list<string> $filterParams
	 * @return list<array<string, mixed>>
	 */
	private function fullTextSearch(ParsedQuery $query, string $filterSql, array $filterParams, int $limit, int $offset): array {
		$t = $this->schemaService->getTableName();
		// The inner query filters, ranks and pages; ts_headline (expensive) only runs on the page.
		// Documents containing every search term come first, then partial (OR) matches.
		$sql = <<<SQL
			WITH q AS (SELECT {$query->rankSql} AS rank_query)
			SELECT m.document_id, m.title, m.source, m.hash, m.modified_time, m.rank, m.total,
				   ts_headline(m.config, m.body, m.rank_query, ?) AS excerpt
			FROM (
				SELECT d.id, d.document_id, d.title, d.source, d.hash, d.modified_time, d.config,
					   CASE WHEN d.content <> '' THEN d.content ELSE d.parts_text END AS body,
					   q.rank_query,
					   ({$query->allSql}) AS all_match,
					   ts_rank_cd(d.tsv, q.rank_query, 32) AS rank,
					   count(*) OVER () AS total
				FROM $t d, q
				WHERE {$query->sql} AND $filterSql
				ORDER BY all_match DESC, rank DESC, d.modified_time DESC, d.id
				LIMIT ? OFFSET ?
			) m
			ORDER BY m.all_match DESC, m.rank DESC, m.modified_time DESC, m.id
			SQL;

		$params = array_merge(
			$query->rankParams, [self::HEADLINE_OPTIONS], $query->allParams, $query->params,
			$filterParams, [(string)$limit, (string)$offset]
		);
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
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
