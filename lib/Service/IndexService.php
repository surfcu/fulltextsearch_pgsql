<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Tools\PgArray;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class IndexService {

	public function __construct(
		private IDBConnection $db,
		private SchemaService $schemaService,
		private ConfigService $configService,
		private ContentExtractor $extractor,
		private VocabularyService $vocabulary,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Insert or update a document.
	 *
	 * When the provider only flagged metadata as changed (e.g. a share was added), the
	 * stored content and parts are kept instead of being overwritten with nothing.
	 *
	 * @return list<string> warnings worth reporting to the index runner
	 * @throws Throwable when the document could not be stored at all
	 */
	public function indexDocument(IIndexDocument $document): array {
		$index = $document->getIndex();
		$updateAll = !$index->isStatus(IIndex::INDEX_META)
			&& !$index->isStatus(IIndex::INDEX_CONTENT)
			&& !$index->isStatus(IIndex::INDEX_PARTS);
		$updateContent = $updateAll || $index->isStatus(IIndex::INDEX_CONTENT);
		$updateParts = $updateAll || $index->isStatus(IIndex::INDEX_PARTS);

		$warnings = [];
		$content = '';
		if ($updateContent) {
			$extracted = $this->extractor->extract($document);
			$content = $extracted->text;
			if ($extracted->warning !== null) {
				$warnings[] = $extracted->warning;
			}
		}

		try {
			$this->upsert($document, $content, $updateContent, $updateParts);
		} catch (Throwable $e) {
			if ($content === '') {
				throw $e;
			}
			// Most likely "string is too long for tsvector": keep the document findable by title.
			$this->logger->warning('Indexing content failed, retrying without content', [
				'provider' => $document->getProviderId(),
				'document' => $document->getId(),
				'exception' => $e,
			]);
			$this->upsert($document, '', true, $updateParts);
			$warnings[] = 'indexed without content: ' . $e->getMessage();
		}

		if ($this->configService->useTypoCorrection()) {
			try {
				$this->vocabulary->addDocument($document->getProviderId(), $document->getId());
			} catch (Throwable $e) {
				// Typo correction is a nicety; never fail indexing over it.
				$this->logger->warning('Could not update the typo correction vocabulary', ['exception' => $e]);
			}
		}

		return $warnings;
	}

	public function deleteDocument(string $providerId, string $documentId): void {
		$this->db->executeStatement(
			'DELETE FROM ' . $this->schemaService->getTableName() . ' WHERE provider_id = ? AND document_id = ?',
			[$providerId, $documentId]
		);
	}

	public function resetProvider(string $providerId): void {
		if ($providerId === 'all') {
			$this->schemaService->truncate();
			return;
		}
		$this->db->executeStatement(
			'DELETE FROM ' . $this->schemaService->getTableName() . ' WHERE provider_id = ?',
			[$providerId]
		);
	}

	private function upsert(IIndexDocument $document, string $content, bool $updateContent, bool $updateParts): void {
		$access = $document->getAccess();
		$extractor = $this->extractor;

		$tags = array_map([$extractor, 'sanitize'], $this->strings($document->getTags()));
		$metaTags = array_map([$extractor, 'sanitize'], $this->strings($document->getMetaTags()));
		$parts = [];
		foreach ($document->getParts() as $name => $value) {
			$parts[(string)$name] = $extractor->clean((string)$value);
		}

		$t = $this->schemaService->getTableName();
		$this->db->executeStatement(<<<SQL
			INSERT INTO $t AS d (
				provider_id, document_id, owner_id, access, links, tags, metatags, subtags,
				source, hash, modified_time, indexed_at, config,
				title, tags_text, parts, parts_text, content, info
			) VALUES (
				?, ?, ?, CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]), CAST(? AS text[]),
				?, ?, ?, ?, CAST(? AS regconfig),
				?, ?, CAST(? AS jsonb), ?, ?, CAST(? AS jsonb)
			)
			ON CONFLICT (provider_id, document_id) DO UPDATE SET
				owner_id = EXCLUDED.owner_id,
				access = EXCLUDED.access,
				links = EXCLUDED.links,
				tags = EXCLUDED.tags,
				metatags = EXCLUDED.metatags,
				subtags = EXCLUDED.subtags,
				source = EXCLUDED.source,
				hash = EXCLUDED.hash,
				modified_time = EXCLUDED.modified_time,
				indexed_at = EXCLUDED.indexed_at,
				config = EXCLUDED.config,
				title = EXCLUDED.title,
				tags_text = EXCLUDED.tags_text,
				info = EXCLUDED.info,
				parts = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.parts ELSE d.parts END,
				parts_text = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.parts_text ELSE d.parts_text END,
				content = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.content ELSE d.content END
			SQL,
			[
				$document->getProviderId(),
				$document->getId(),
				$access->getOwnerId(),
				PgArray::toLiteral(AccessTokens::forDocument($access)),
				PgArray::toLiteral($this->strings($access->getLinks())),
				PgArray::toLiteral($tags),
				PgArray::toLiteral($metaTags),
				PgArray::toLiteral($this->strings($document->getSubTags(true))),
				$document->getSource(),
				$document->getHash(),
				(string)$document->getModifiedTime(),
				(string)time(),
				$this->configService->getLanguage(),
				$extractor->sanitize($document->getTitle()),
				$extractor->clean(implode(' ', array_merge($tags, $metaTags))),
				json_encode((object)$parts, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
				implode(' ', $parts),
				$content,
				json_encode((object)$document->getInfoAll(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
				$updateParts ? 'true' : 'false',
				$updateParts ? 'true' : 'false',
				$updateContent ? 'true' : 'false',
			]
		);
	}

	/** @return list<string> */
	private function strings(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_scalar($value) && (string)$value !== '') {
				$out[] = (string)$value;
			}
		}
		return array_values(array_unique($out));
	}
}
