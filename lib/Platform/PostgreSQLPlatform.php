<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Platform;

use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\IndexService;
use OCA\FullTextSearch_PgSql\Service\SearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;

class PostgreSQLPlatform implements IFullTextSearchPlatform {
    
    private ConfigService $configService;
    private IndexService $indexService;
    private SearchService $searchService;

    public function __construct(
        ConfigService $configService,
        IndexService $indexService,
        SearchService $searchService
    ) {
        $this->configService = $configService;
        $this->indexService = $indexService;
        $this->searchService = $searchService;
    }

    /**
     * Return the unique ID of the platform
     */
    public function getId(): string {
        return 'pgsql';
    }

    /**
     * Return the display name of the platform
     */
    public function getName(): string {
        return 'PostgreSQL Full Text Search';
    }

    /**
     * Return the configuration array
     */
    public function getConfiguration(): array {
        return $this->configService->getConfig();
    }

    /**
     * Set configuration
     */
    public function setConfiguration(array $config): void {
        $this->configService->setConfig($config);
    }

    /**
     * Check if the platform is properly configured and available
     */
    public function testPlatform(): bool {
        return $this->indexService->testPlatform();
    }

    /**
     * Load platform configuration panel
     */
    public function loadPlatform(): void {
        $this->indexService->initializePlatform();
    }

    /**
     * Called when initializing the index
     */
    public function initializeIndex(array $providers): void {
        $this->indexService->initializeIndex($providers);
    }

    /**
     * Reset the indexes for a specific provider
     */
    public function resetIndex(string $providerId): void {
        $this->indexService->resetIndex($providerId);
    }

    /**
     * Delete the complete indexes
     */
    public function deleteIndexes(): void {
        $this->indexService->deleteIndexes();
    }

    /**
     * Index a document
     */
    public function indexDocument(IIndexDocument $document, IRunner $runner): IIndex {
        return $this->indexService->indexDocument($document, $runner);
    }

    /**
     * Update a document
     */
    public function updateDocument(IIndexDocument $document, IRunner $runner): IIndex {
        return $this->indexService->updateDocument($document, $runner);
    }

    /**
     * Delete a document from the index
     */
    public function deleteDocument(string $providerId, string $documentId): void {
        $this->indexService->deleteDocument($providerId, $documentId);
    }

    /**
     * Perform a search
     */
    public function search(ISearchRequest $request, ISearchResult $result): void {
        $this->searchService->search($request, $result);
    }
}
