<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Owns the index table.
 *
 * The table is created with raw SQL and deliberately named so it does NOT start with
 * Nextcloud's table prefix. Nextcloud only introspects tables matching its prefix, and
 * Doctrine cannot map tsvector / text[] / regconfig columns: if this table carried the
 * prefix, every later migration of every app on the instance would fail with
 * "Unknown database type tsvector requested".
 */
class SchemaService {

	private ?bool $trigram = null;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function isPostgres(): bool {
		return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
	}

	/** e.g. "ftspg_oc_index" for the default "oc_" prefix. */
	public function getTableName(): string {
		$prefix = strtolower($this->config->getSystemValueString('dbtableprefix', 'oc_'));
		if (!preg_match('/^[a-z0-9_]*$/', $prefix)) {
			throw new RuntimeException('Unsupported characters in dbtableprefix');
		}
		return 'ftspg_' . $prefix . 'index';
	}

	public function tableExists(): bool {
		$result = $this->db->executeQuery('SELECT to_regclass(?) IS NOT NULL', [$this->getTableName()]);
		$exists = (bool)$result->fetchOne();
		$result->closeCursor();
		return $exists;
	}

	/**
	 * Create the table and indexes if they are missing. Idempotent; safe to call often.
	 */
	public function ensureSchema(): void {
		if (!$this->isPostgres()) {
			throw new RuntimeException('fulltextsearch_pgsql requires Nextcloud to run on PostgreSQL');
		}

		$this->dropLegacyTable();
		$this->tryCreateTrigramExtension();

		$t = $this->getTableName();
		$this->db->executeStatement(<<<SQL
			CREATE TABLE IF NOT EXISTS $t (
				id            bigserial    PRIMARY KEY,
				provider_id   varchar(64)  NOT NULL,
				document_id   varchar(254) NOT NULL,
				owner_id      varchar(64)  NOT NULL DEFAULT '',
				access        text[]       NOT NULL DEFAULT '{}',
				links         text[]       NOT NULL DEFAULT '{}',
				tags          text[]       NOT NULL DEFAULT '{}',
				metatags      text[]       NOT NULL DEFAULT '{}',
				subtags       text[]       NOT NULL DEFAULT '{}',
				source        varchar(64)  NOT NULL DEFAULT '',
				hash          varchar(128) NOT NULL DEFAULT '',
				modified_time bigint       NOT NULL DEFAULT 0,
				indexed_at    bigint       NOT NULL DEFAULT 0,
				config        regconfig    NOT NULL,
				title         text         NOT NULL DEFAULT '',
				tags_text     text         NOT NULL DEFAULT '',
				parts         jsonb        NOT NULL DEFAULT '{}',
				parts_text    text         NOT NULL DEFAULT '',
				content       text         NOT NULL DEFAULT '',
				info          jsonb        NOT NULL DEFAULT '{}',
				tsv tsvector GENERATED ALWAYS AS (
					setweight(to_tsvector(config, {$this->normalized('title')}), 'A')
					|| setweight(to_tsvector(config, {$this->normalized('tags_text')}), 'B')
					|| setweight(to_tsvector(config, {$this->normalized('parts_text')}), 'C')
					|| setweight(to_tsvector(config, {$this->normalized('content')}), 'D')
				) STORED,
				CONSTRAINT {$t}_doc_uniq UNIQUE (provider_id, document_id)
			)
			SQL);

		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_tsv_idx ON $t USING GIN (tsv)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_access_idx ON $t USING GIN (access)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_metatags_idx ON $t USING GIN (metatags)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_subtags_idx ON $t USING GIN (subtags)");

		if ($this->hasTrigram()) {
			// Titles only: a trigram index over full content would be very large for little gain.
			$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_title_trgm_idx ON $t USING GIN (title gin_trgm_ops)");
		}
	}

	/**
	 * PostgreSQL lowercases with the database locale, which is almost never tr_TR, so Turkish
	 * "I" would become "i" instead of "ı" and uppercase Turkish words would never match.
	 * Map I->ı and İ->i first for Turkish rows; TsQueryBuilder does the same to search terms.
	 */
	private function normalized(string $column): string {
		return "CASE WHEN config = 'turkish'::regconfig THEN translate($column, 'Iİ', 'ıi') ELSE $column END";
	}

	public function dropSchema(): void {
		$this->db->executeStatement('DROP TABLE IF EXISTS ' . $this->getTableName());
	}

	/** Remove every indexed document, keeping the table. */
	public function truncate(): void {
		$this->db->executeStatement('TRUNCATE ' . $this->getTableName());
	}

	public function hasTrigram(): bool {
		if ($this->trigram === null) {
			$result = $this->db->executeQuery("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'");
			$this->trigram = $result->fetchOne() !== false;
			$result->closeCursor();
		}
		return $this->trigram;
	}

	/** @return string[] text search configurations installed on this server, e.g. english, turkish, simple */
	public function getTextSearchConfigs(): array {
		$result = $this->db->executeQuery('SELECT cfgname FROM pg_ts_config ORDER BY cfgname');
		$configs = array_map('strval', array_column($result->fetchAll(), 'cfgname'));
		$result->closeCursor();
		return $configs;
	}

	private function tryCreateTrigramExtension(): void {
		if ($this->hasTrigram() || $this->db->inTransaction()) {
			// A failed statement inside a transaction would abort it, so only try outside one.
			return;
		}
		try {
			// pg_trgm is a "trusted" extension since PostgreSQL 13, so the database owner can create it.
			$this->db->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
			$this->trigram = null;
		} catch (Throwable $e) {
			$this->logger->info('pg_trgm extension not available, fuzzy title matching disabled: ' . $e->getMessage());
		}
	}

	/**
	 * Version 1.0.0 tried to create a prefixed table with a tsvector column. If that ever
	 * succeeded it breaks Doctrine schema introspection, and its content is unusable anyway.
	 */
	private function dropLegacyTable(): void {
		$this->db->executeStatement('DROP TABLE IF EXISTS *PREFIX*fts_pgsql_index');
	}
}
