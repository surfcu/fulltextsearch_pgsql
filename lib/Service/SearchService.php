<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Db\IndexMapper;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\SearchResult;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SearchService {
    
    private IndexMapper $indexMapper;
    private ConfigService $configService;
    private IUserSession $userSession;
    private LoggerInterface $logger;

    public function __construct(
        IndexMapper $indexMapper,
        ConfigService $configService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->indexMapper = $indexMapper;
        $this->configService = $configService;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Perform a search using PostgreSQL full-text search
     */
    public function search(ISearchRequest $request, ISearchResult $result): void {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return;
            }

            $userId = $user->getUID();
            $search = $request->getSearch();
            
            if (empty($search)) {
                return;
            }

            // Sanitize search query
            $search = $this->sanitizeSearchQuery($search);

            // Get search parameters
            $providers = $request->getProviders();
            $tags = $request->getTags();
            $metaTags = $request->getMetaTags();
            $limit = min($request->getSize(), $this->configService->getMaxResults());
            $offset = $request->getPage() * $limit;

            // Perform the search
            $documents = $this->indexMapper->search(
                $search,
                $userId,
                $providers,
                $tags,
                $metaTags,
                $limit,
                $offset,
                $this->configService->getLanguage(),
                $this->configService->useTrigramSearch()
            );

            // Get total count
            $total = $this->indexMapper->searchCount(
                $search,
                $userId,
                $providers,
                $tags,
                $metaTags,
                $this->configService->getLanguage(),
                $this->configService->useTrigramSearch()
            );

            // Add documents to result
            foreach ($documents as $doc) {
                $searchResult = new SearchResult();
                $searchResult->setProviderId($doc['provider_id']);
                $searchResult->setDocumentId($doc['document_id']);
                $searchResult->setTitle($doc['title']);
                $searchResult->setExcerpt($this->generateExcerpt($doc['content'], $search));
                $searchResult->setScore((float)$doc['rank']);
                
                $result->addDocument($searchResult);
            }

            $result->setTotal($total);
            $result->setMaxScore(1.0);

        } catch (\Exception $e) {
            $this->logger->error('Error performing search: ' . $e->getMessage());
        }
    }

    /**
     * Sanitize search query for PostgreSQL full-text search
     */
    private function sanitizeSearchQuery(string $query): string {
        // Remove special characters that could break ts_query
        $query = preg_replace('/[^\w\s\-]/', ' ', $query);
        
        // Collapse multiple spaces
        $query = preg_replace('/\s+/', ' ', $query);
        
        return trim($query);
    }

    /**
     * Generate search excerpt with highlighted terms
     */
    private function generateExcerpt(string $content, string $search, int $length = 200): string {
        $content = strip_tags($content);
        
        // Find the position of the search term
        $searchTerms = explode(' ', $search);
        $position = 0;
        
        foreach ($searchTerms as $term) {
            $pos = stripos($content, $term);
            if ($pos !== false) {
                $position = $pos;
                break;
            }
        }
        
        // Extract excerpt around the search term
        $start = max(0, $position - (int)($length / 2));
        $excerpt = substr($content, $start, $length);
        
        // Add ellipsis if needed
        if ($start > 0) {
            $excerpt = '...' . $excerpt;
        }
        if (strlen($content) > $start + $length) {
            $excerpt .= '...';
        }
        
        return $excerpt;
    }
}
