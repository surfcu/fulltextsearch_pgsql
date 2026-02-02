<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Db\IndexMapper;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class IndexService {
    
    private IDBConnection $db;
    private IndexMapper $indexMapper;
    private ConfigService $configService;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IndexMapper $indexMapper,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->indexMapper = $indexMapper;
        $this->configService = $configService;
        $this->logger = $logger;
    }

    /**
     * Test if PostgreSQL platform is available and properly configured
     */
    public function testPlatform(): bool {
        try {
            // Check if we're using PostgreSQL
            if ($this->db->getDatabasePlatform()->getName() !== 'postgresql') {
                $this->logger->error('Database is not PostgreSQL');
                return false;
            }

            // Check if required extensions are available
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')
                ->from('pg_extension')
                ->where($qb->expr()->eq('extname', $qb->createNamedParameter('pg_trgm')))
                ->executeQuery();
            
            $hasExtension = $result->rowCount() > 0;
            $result->closeCursor();

            if (!$hasExtension) {
                $this->logger->warning('pg_trgm extension not found. Trigram search will be disabled.');
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error testing PostgreSQL platform: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize the platform by creating necessary database structures
     */
    public function initializePlatform(): void {
        try {
            // Enable pg_trgm extension if not already enabled
            if ($this->configService->useTrigramSearch()) {
                $this->db->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error initializing platform: ' . $e->getMessage());
        }
    }

    /**
     * Initialize indexes for all providers
     */
    public function initializeIndex(array $providers): void {
        try {
            $this->indexMapper->createIndexTable();
            $this->logger->info('Index table initialized for ' . count($providers) . ' providers');
        } catch (\Exception $e) {
            $this->logger->error('Error initializing index: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reset index for a specific provider
     */
    public function resetIndex(string $providerId): void {
        try {
            $this->indexMapper->deleteByProvider($providerId);
            $this->logger->info('Reset index for provider: ' . $providerId);
        } catch (\Exception $e) {
            $this->logger->error('Error resetting index: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete all indexes
     */
    public function deleteIndexes(): void {
        try {
            $this->indexMapper->deleteAll();
            $this->logger->info('All indexes deleted');
        } catch (\Exception $e) {
            $this->logger->error('Error deleting indexes: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Index a document
     */
    public function indexDocument(IIndexDocument $document, IRunner $runner): IIndex {
        try {
            $index = $document->getIndex();
            
            // Prepare document content for indexing
            $content = $this->prepareContent($document);
            
            // Insert into database with ts_vector
            $this->indexMapper->insertDocument(
                $document->getProviderId(),
                $document->getId(),
                $document->getAccess(),
                $document->getOwnerId(),
                $content,
                $document->getTitle(),
                json_encode($document->getTags()),
                json_encode($document->getMetaTags()),
                json_encode($document->getSubTags()),
                $this->configService->getLanguage()
            );

            $index->setStatus(IIndex::INDEX_OK);
            $index->setMessage('Document indexed successfully');
            
            return $index;
        } catch (\Exception $e) {
            $this->logger->error('Error indexing document: ' . $e->getMessage());
            $index = $document->getIndex();
            $index->setStatus(IIndex::INDEX_FAILED);
            $index->setMessage($e->getMessage());
            return $index;
        }
    }

    /**
     * Update a document
     */
    public function updateDocument(IIndexDocument $document, IRunner $runner): IIndex {
        try {
            // Delete old document
            $this->deleteDocument($document->getProviderId(), $document->getId());
            
            // Index new version
            return $this->indexDocument($document, $runner);
        } catch (\Exception $e) {
            $this->logger->error('Error updating document: ' . $e->getMessage());
            $index = $document->getIndex();
            $index->setStatus(IIndex::INDEX_FAILED);
            $index->setMessage($e->getMessage());
            return $index;
        }
    }

    /**
     * Delete a document from the index
     */
    public function deleteDocument(string $providerId, string $documentId): void {
        try {
            $this->indexMapper->deleteDocument($providerId, $documentId);
        } catch (\Exception $e) {
            $this->logger->error('Error deleting document: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Prepare document content for indexing
     */
    private function prepareContent(IIndexDocument $document): string {
        $parts = [];
        
        // Add title with higher weight
        if ($document->getTitle()) {
            $parts[] = $document->getTitle();
            $parts[] = $document->getTitle(); // Add twice for higher weight
        }
        
        // Add content
        if ($document->getContent()) {
            $parts[] = $document->getContent();
        }
        
        // Add tags
        foreach ($document->getTags() as $tag) {
            $parts[] = $tag;
        }
        
        // Add meta tags
        foreach ($document->getMetaTags() as $meta) {
            $parts[] = $meta;
        }
        
        return implode(' ', $parts);
    }
}
