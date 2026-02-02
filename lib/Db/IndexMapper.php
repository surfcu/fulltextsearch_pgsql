<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class IndexMapper {
    
    private const TABLE_NAME = 'fts_pgsql_index';
    
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Create the index table with ts_vector column
     */
    public function createIndexTable(): void {
        $schema = $this->db->getSchemaManager();
        
        if ($schema->tablesExist([self::TABLE_NAME])) {
            return;
        }

        $table = $schema->createSchema()->createTable(self::TABLE_NAME);
        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('provider_id', 'string', [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('document_id', 'string', [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('user_id', 'string', [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('access', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('content', 'text', [
            'notnull' => true,
        ]);
        $table->addColumn('title', 'string', [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('tags', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('metatags', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('subtags', 'text', [
            'notnull' => false,
        ]);
        $table->addColumn('indexed_at', 'integer', [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['provider_id'], 'fts_pgsql_provider_idx');
        $table->addIndex(['document_id'], 'fts_pgsql_document_idx');
        $table->addIndex(['user_id'], 'fts_pgsql_user_idx');
        $table->addUniqueIndex(['provider_id', 'document_id'], 'fts_pgsql_unique_idx');

        $schema->createTable($table);

        // Create ts_vector column and GIN index using raw SQL
        $this->db->executeStatement(
            "ALTER TABLE " . self::TABLE_NAME . " ADD COLUMN content_tsv tsvector"
        );
        
        $this->db->executeStatement(
            "CREATE INDEX fts_pgsql_content_tsv_idx ON " . self::TABLE_NAME . " USING GIN (content_tsv)"
        );

        // Create trigram index for fuzzy search if available
        try {
            $this->db->executeStatement(
                "CREATE INDEX fts_pgsql_content_trgm_idx ON " . self::TABLE_NAME . " USING GIN (content gin_trgm_ops)"
            );
            $this->db->executeStatement(
                "CREATE INDEX fts_pgsql_title_trgm_idx ON " . self::TABLE_NAME . " USING GIN (title gin_trgm_ops)"
            );
        } catch (\Exception $e) {
            // Trigram extension might not be available, which is okay
        }
    }

    /**
     * Insert a document into the index
     */
    public function insertDocument(
        string $providerId,
        string $documentId,
        ?array $access,
        string $userId,
        string $content,
        ?string $title,
        string $tags,
        string $metaTags,
        string $subTags,
        string $language
    ): void {
        // Delete existing document first
        $this->deleteDocument($providerId, $documentId);

        $qb = $this->db->getQueryBuilder();
        
        $qb->insert(self::TABLE_NAME)
            ->values([
                'provider_id' => $qb->createNamedParameter($providerId),
                'document_id' => $qb->createNamedParameter($documentId),
                'user_id' => $qb->createNamedParameter($userId),
                'access' => $qb->createNamedParameter($access ? json_encode($access) : null),
                'content' => $qb->createNamedParameter($content),
                'title' => $qb->createNamedParameter($title),
                'tags' => $qb->createNamedParameter($tags),
                'metatags' => $qb->createNamedParameter($metaTags),
                'subtags' => $qb->createNamedParameter($subTags),
                'indexed_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        // Update ts_vector column
        $this->db->executeStatement(
            "UPDATE " . self::TABLE_NAME . " 
             SET content_tsv = to_tsvector(?, content) 
             WHERE provider_id = ? AND document_id = ?",
            [$language, $providerId, $documentId]
        );
    }

    /**
     * Delete a specific document
     */
    public function deleteDocument(string $providerId, string $documentId): void {
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete(self::TABLE_NAME)
            ->where($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId)))
            ->andWhere($qb->expr()->eq('document_id', $qb->createNamedParameter($documentId)))
            ->executeStatement();
    }

    /**
     * Delete all documents for a provider
     */
    public function deleteByProvider(string $providerId): void {
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete(self::TABLE_NAME)
            ->where($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId)))
            ->executeStatement();
    }

    /**
     * Delete all documents
     */
    public function deleteAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE_NAME)->executeStatement();
    }

    /**
     * Search documents using PostgreSQL full-text search
     */
    public function search(
        string $query,
        string $userId,
        array $providers,
        array $tags,
        array $metaTags,
        int $limit,
        int $offset,
        string $language,
        bool $useTrigram
    ): array {
        // Build ts_query from search terms
        $searchTerms = explode(' ', $query);
        $tsQuery = implode(' & ', array_map(function($term) {
            return $term . ':*';
        }, $searchTerms));

        $sql = "SELECT 
                    provider_id,
                    document_id,
                    title,
                    content,
                    ts_rank(content_tsv, to_tsquery(?, ?)) as rank
                FROM " . self::TABLE_NAME . "
                WHERE (user_id = ? OR access IS NULL OR access = '[]')";

        $params = [$language, $tsQuery, $userId];
        
        // Add ts_query condition
        $sql .= " AND content_tsv @@ to_tsquery(?, ?)";
        $params[] = $language;
        $params[] = $tsQuery;

        // Filter by providers
        if (!empty($providers)) {
            $placeholders = implode(',', array_fill(0, count($providers), '?'));
            $sql .= " AND provider_id IN ($placeholders)";
            $params = array_merge($params, $providers);
        }

        // Filter by tags
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $sql .= " AND tags LIKE ?";
                $params[] = '%' . $tag . '%';
            }
        }

        // Filter by metatags
        if (!empty($metaTags)) {
            foreach ($metaTags as $metaTag) {
                $sql .= " AND metatags LIKE ?";
                $params[] = '%' . $metaTag . '%';
            }
        }

        // Order by rank
        $sql .= " ORDER BY rank DESC";
        
        // Add limit and offset
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $result = $this->db->executeQuery($sql, $params);
        $documents = $result->fetchAll();
        $result->closeCursor();

        return $documents;
    }

    /**
     * Get total count of search results
     */
    public function searchCount(
        string $query,
        string $userId,
        array $providers,
        array $tags,
        array $metaTags,
        string $language,
        bool $useTrigram
    ): int {
        $searchTerms = explode(' ', $query);
        $tsQuery = implode(' & ', array_map(function($term) {
            return $term . ':*';
        }, $searchTerms));

        $sql = "SELECT COUNT(*) as total
                FROM " . self::TABLE_NAME . "
                WHERE (user_id = ? OR access IS NULL OR access = '[]')";

        $params = [$userId];
        
        // Add ts_query condition
        $sql .= " AND content_tsv @@ to_tsquery(?, ?)";
        $params[] = $language;
        $params[] = $tsQuery;

        // Filter by providers
        if (!empty($providers)) {
            $placeholders = implode(',', array_fill(0, count($providers), '?'));
            $sql .= " AND provider_id IN ($placeholders)";
            $params = array_merge($params, $providers);
        }

        // Filter by tags
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $sql .= " AND tags LIKE ?";
                $params[] = '%' . $tag . '%';
            }
        }

        // Filter by metatags
        if (!empty($metaTags)) {
            foreach ($metaTags as $metaTag) {
                $sql .= " AND metatags LIKE ?";
                $params[] = '%' . $metaTag . '%';
            }
        }

        $result = $this->db->executeQuery($sql, $params);
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['total'] ?? 0);
    }
}
