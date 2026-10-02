<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Platform;

use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;
use Throwable;

class PostgreSQLPlatform implements IFullTextSearchPlatform {

	private ?IRunner $runner = null;

	public function __construct(
		private ConfigService $configService,
		private SchemaService $schemaService,
		private IndexService $indexService,
		private SearchService $searchService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'pgsql';
	}

	public function getName(): string {
		return 'PostgreSQL';
	}

	public function getConfiguration(): array {
		$config = $this->configService->getConfig();
		$config['table'] = $this->schemaService->getTableName();
		$config['pg_trgm'] = $this->schemaService->isPostgres() && $this->schemaService->hasTrigram();
		return $config;
	}

	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}

	public function loadPlatform() {
		// Normally done by the install/upgrade repair step; this covers skipped or failed runs.
		if ($this->schemaService->isPostgres() && !$this->schemaService->isCurrent()) {
			$this->schemaService->ensureSchema();
		}
	}

	public function testPlatform(): bool {
		try {
			if (!$this->schemaService->isPostgres()) {
				$this->logger->error('fulltextsearch_pgsql requires Nextcloud to run on PostgreSQL');
				return false;
			}
			$this->schemaService->ensureSchema();
			return $this->schemaService->tableExists();
		} catch (Throwable $e) {
			$this->logger->error('PostgreSQL full text search platform test failed', ['exception' => $e]);
			return false;
		}
	}

	public function initializeIndex() {
		$this->schemaService->ensureSchema();
	}

	public function resetIndex(string $providerId) {
		$this->indexService->resetProvider($providerId);
	}

	/**
	 * @param IIndex[] $indexes
	 */
	public function deleteIndexes(array $indexes) {
		foreach ($indexes as $index) {
			try {
				$this->indexService->deleteDocument($index->getProviderId(), $index->getDocumentId());
				$this->runner?->newIndexResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Throwable $e) {
				$this->logger->warning('Could not delete document from index', ['exception' => $e]);
				$this->runner?->newIndexResult($index, 'index not deleted', 'issue while deleting index', IRunner::RESULT_TYPE_WARNING);
			}
		}
	}

	public function indexDocument(IIndexDocument $document): IIndex {
		$document->initHash();
		$index = $document->getIndex();
		$this->runner?->updateAction('indexDocument', true);

		try {
			$warnings = $this->indexService->indexDocument($document);
		} catch (Throwable $e) {
			$this->logger->warning('Could not index document', [
				'provider' => $document->getProviderId(),
				'document' => $document->getId(),
				'exception' => $e,
			]);
			$index->setStatus(IIndex::INDEX_FAILED);
			$index->addError($e->getMessage(), get_class($e), IIndex::ERROR_SEV_3);
			$this->runner?->newIndexError($index, $e->getMessage(), get_class($e), IIndex::ERROR_SEV_3);
			$this->runner?->newIndexResult($index, '', 'fail', IRunner::RESULT_TYPE_FAIL);
			return $index;
		}

		$index->setLastIndex();
		if ($index->getErrorCount() === 0) {
			$index->setStatus(IIndex::INDEX_DONE);
		}

		if ($warnings === []) {
			$this->runner?->newIndexResult($index, 'ok', 'ok', IRunner::RESULT_TYPE_SUCCESS);
		} else {
			$this->runner?->newIndexResult($index, implode('; ', $warnings), 'warning', IRunner::RESULT_TYPE_WARNING);
		}

		return $index;
	}

	public function searchRequest(ISearchResult $result, IDocumentAccess $access) {
		$this->searchService->searchRequest($result, $access);
	}

	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		return $this->searchService->getDocument($providerId, $documentId);
	}
}
