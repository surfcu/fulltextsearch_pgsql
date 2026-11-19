<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCA\FullTextSearch_PgSql\Tools\Fold;
use OCA\FullTextSearch_PgSql\Tools\PgArray;
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
 * Doctrine cannot map tsvector / text[] columns: if this table carried the
 * prefix, every later migration of every app on the instance would fail with
 * "Unknown database type tsvector requested".
 */
class SchemaService {

	/** Version of the generated column definitions; bump when they change. */
	private const SCHEMA_MARKER = 'ftspg schema 3';

	private ?bool $trigram = null;
	private ?string $trigramSchema = null;
	private ?string $schema = null;
	/** @var string[]|null */
	private ?array $textSearchConfigs = null;
	private ?bool $localeFolding = null;

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

	/**
	 * Vocabulary of every word in the index, for typo correction ("ftspg_oc_words").
	 * Outside Nextcloud's prefix for the same reason as the index table.
	 */
	public function getWordsTableName(): string {
		return substr($this->getTableName(), 0, -strlen('index')) . 'words';
	}

	/** IMMUTABLE PARALLEL SAFE SQL wrapper casting text config names to pg_catalog.regconfig. */
	public function getRegconfigFunctionName(): string {
		return substr($this->getTableName(), 0, -strlen('index')) . 'regconfig';
	}

	public function getQualifiedTableName(): string {
		return $this->quoteIdent($this->getSchemaName()) . '.' . $this->quoteIdent($this->getTableName());
	}

	public function getQualifiedWordsTableName(): string {
		return $this->quoteIdent($this->getSchemaName()) . '.' . $this->quoteIdent($this->getWordsTableName());
	}

	public function getQualifiedRegconfigFunctionName(): string {
		return $this->quoteIdent($this->getSchemaName()) . '.' . $this->quoteIdent($this->getRegconfigFunctionName());
	}

	public function getTrigramSchema(): ?string {
		$this->hasTrigram();
		return $this->trigramSchema;
	}

	/** True when the table exists and its generated columns match this version of the app. */
	public function isCurrent(): bool {
		$qt = $this->getQualifiedTableName();
		$result = $this->db->executeQuery(
			"SELECT col_description(pg_catalog.to_regclass(?), attnum) FROM pg_catalog.pg_attribute WHERE attrelid = pg_catalog.to_regclass(?) AND attname = 'tsv' AND NOT attisdropped",
			[$qt, $qt]
		);
		$marker = $result->fetchOne();
		$result->closeCursor();
		return $marker === self::SCHEMA_MARKER;
	}

	public function tableExists(): bool {
		$result = $this->db->executeQuery('SELECT pg_catalog.to_regclass(?) IS NOT NULL', [$this->getQualifiedTableName()]);
		$exists = (bool)$result->fetchOne();
		$result->closeCursor();
		return $exists;
	}

	public function hasSubtagsMapColumn(): bool {
		$result = $this->db->executeQuery(
			"SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_attribute WHERE attrelid = pg_catalog.to_regclass(?) AND attname = 'subtags_map' AND NOT attisdropped)",
			[$this->getQualifiedTableName()]
		);
		$exists = (bool)$result->fetchOne();
		$result->closeCursor();
		return $exists;
	}

	/**
	 * Create the table and indexes if they are missing. Serialized and atomic.
	 */
	public function ensureSchema(): void {
		if (!$this->isPostgres()) {
			throw new RuntimeException('fulltextsearch_pgsql requires Nextcloud to run on PostgreSQL');
		}

		// Keep extension setup outside the transaction so a permission error never aborts the rebuild.
		$this->trigram = null;
		$this->trigramSchema = null;
		$this->schema = null;
		$this->tryCreateTrigramExtension();

		$t = $this->getTableName();
		$w = $this->getWordsTableName();
		$qs = $this->quoteIdent($this->getSchemaName());
		$qt = $this->getQualifiedTableName();
		$qfn = $this->getQualifiedRegconfigFunctionName();
		$ownTransaction = !$this->db->inTransaction();
		$savepoint = 'ftspg_ensure_schema';
		if ($ownTransaction) {
			$this->db->beginTransaction();
		} else {
			$this->db->executeStatement("SAVEPOINT $savepoint");
		}

		try {
			$this->db->executeQuery(
				'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
				['fulltextsearch_pgsql', $t]
			)->closeCursor();

			if (!$this->isCurrent()) {
				$this->dropLegacyTable();
				$this->createIndexTable($t, $qt);
				$this->ensureRegconfigFunction($qfn);
				$this->ensureGeneratedColumns();
				$this->ensureIndexTableIndexes($t, $qt);
				$this->ensureWordsTable();
				$this->db->executeStatement("ANALYZE $qt");
			} else {
				if (!$this->hasSubtagsMapColumn()) {
					$this->db->executeStatement("ALTER TABLE $qt ADD COLUMN IF NOT EXISTS subtags_map jsonb NOT NULL DEFAULT '{}'");
				}
				if (!$this->hasAllIndexesAndWords($qs, $t, $w, $qfn)) {
					$this->ensureRegconfigFunction($qfn);
					$this->ensureIndexTableIndexes($t, $qt);
					$this->ensureWordsTable();
				}
			}

			if ($ownTransaction) {
				$this->db->commit();
			} else {
				$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
			}
		} catch (Throwable $e) {
			if ($ownTransaction) {
				if ($this->db->inTransaction()) {
					try {
						$this->db->rollBack();
					} catch (Throwable) {
					}
				}
			} elseif ($this->db->inTransaction()) {
				try {
					$this->db->executeStatement("ROLLBACK TO SAVEPOINT $savepoint");
					$this->db->executeStatement("RELEASE SAVEPOINT $savepoint");
				} catch (Throwable) {
				}
			}
			throw $e;
		}
	}

	private function ensureRegconfigFunction(string $qfn): void {
		$this->db->executeStatement(
			"CREATE OR REPLACE FUNCTION {$qfn}(text) RETURNS pg_catalog.regconfig LANGUAGE sql IMMUTABLE PARALLEL SAFE AS \$\$SELECT \$1::pg_catalog.regconfig\$\$"
		);
	}

	private function createIndexTable(string $t, string $qt): void {
		$this->db->executeStatement(<<<SQL
			CREATE TABLE IF NOT EXISTS $qt (
				id            bigserial    PRIMARY KEY,
				provider_id   text         NOT NULL,
				document_id   text         NOT NULL,
				owner_id      text         NOT NULL DEFAULT '',
				access        text[]       NOT NULL DEFAULT '{}',
				links         text[]       NOT NULL DEFAULT '{}',
				tags          text[]       NOT NULL DEFAULT '{}',
				metatags      text[]       NOT NULL DEFAULT '{}',
				subtags       text[]       NOT NULL DEFAULT '{}',
				subtags_map   jsonb        NOT NULL DEFAULT '{}',
				source        text         NOT NULL DEFAULT '',
				hash          text         NOT NULL DEFAULT '',
				modified_time bigint       NOT NULL DEFAULT 0,
				indexed_at    bigint       NOT NULL DEFAULT 0,
				config        text         NOT NULL,
				title         text         NOT NULL DEFAULT '',
				tags_text     text         NOT NULL DEFAULT '',
				parts         jsonb        NOT NULL DEFAULT '{}',
				parts_text    text         NOT NULL DEFAULT '',
				content       text         NOT NULL DEFAULT '',
				info          jsonb        NOT NULL DEFAULT '{}',
				CONSTRAINT {$t}_doc_uniq UNIQUE (provider_id, document_id)
			)
			SQL);
	}

	private function ensureIndexTableIndexes(string $t, string $qt): void {
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_tsv_idx ON $qt USING GIN (tsv)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_access_idx ON $qt USING GIN (access)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_metatags_idx ON $qt USING GIN (metatags)");
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_subtags_idx ON $qt USING GIN (subtags)");

		$ts = $this->getTrigramSchema();
		if ($ts !== null) {
			// Titles only: a trigram index over full content would be very large for little gain.
			// Serves substring matching (LIKE '%…%') and typo-tolerant matching (<%).
			$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$t}_title_search_trgm_idx ON $qt USING GIN (title_search {$ts}.gin_trgm_ops)");
		}
	}

	private function hasAllIndexesAndWords(string $qs, string $t, string $w, string $qfn): bool {
		$relations = [
			"{$qs}.{$t}_tsv_idx",
			"{$qs}.{$t}_access_idx",
			"{$qs}.{$t}_metatags_idx",
			"{$qs}.{$t}_subtags_idx",
			"{$qs}.{$w}",
			"{$qs}.{$w}_folded_idx",
		];
		if ($this->hasTrigram()) {
			$relations[] = "{$qs}.{$t}_title_search_trgm_idx";
			$relations[] = "{$qs}.{$w}_folded_trgm_idx";
		}
		$result = $this->db->executeQuery(
			'SELECT bool_and(pg_catalog.to_regclass(name) IS NOT NULL) AND pg_catalog.to_regprocedure(?) IS NOT NULL FROM unnest(CAST(? AS text[])) AS name',
			[$qfn . '(text)', PgArray::toLiteral($relations)]
		);
		$allPresent = (bool)$result->fetchOne();
		$result->closeCursor();
		return $allPresent;
	}

	/**
	 * One row per distinct lowercase word. "C" collation makes LIKE 'prefix%' use the
	 * b-tree indexes; "folded" is the word without accents (see Tools\Fold).
	 */
	private function ensureWordsTable(): void {
		$w = $this->getWordsTableName();
		$qw = $this->getQualifiedWordsTableName();
		$this->db->executeStatement(sprintf(<<<'SQL'
			CREATE TABLE IF NOT EXISTS %1$s (
				word   text COLLATE "C" PRIMARY KEY,
				folded text COLLATE "C" GENERATED ALWAYS AS (translate(word, '%2$s', '%3$s')) STORED
			)
			SQL, $qw, Fold::FROM, Fold::TO));
		$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$w}_folded_idx ON $qw (folded)");
		$ts = $this->getTrigramSchema();
		if ($ts !== null) {
			$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$w}_folded_trgm_idx ON $qw USING GIN (folded {$ts}.gin_trgm_ops)");
		}
	}

	/**
	 * The generated columns change between versions. Their definition version is recorded as
	 * a comment on the tsv column; when it is older than SCHEMA_VERSION they are rebuilt in
	 * place. PostgreSQL recomputes them for every row, which locks the table while it runs.
	 */
	private function ensureGeneratedColumns(): void {
		if ($this->isCurrent()) {
			return;
		}
		$qs = $this->quoteIdent($this->getSchemaName());
		$t = $this->getTableName();
		$qt = $this->getQualifiedTableName();
		$qfn = $this->getQualifiedRegconfigFunctionName();
		$this->ensureRegconfigFunction($qfn);

		$this->db->executeStatement("DROP INDEX IF EXISTS {$qs}.{$t}_tsv_idx");
		$this->db->executeStatement("DROP INDEX IF EXISTS {$qs}.{$t}_title_trgm_idx");
		$this->db->executeStatement("DROP INDEX IF EXISTS {$qs}.{$t}_title_search_trgm_idx");
		$this->db->executeStatement("ALTER TABLE $qt DROP COLUMN IF EXISTS tsv, DROP COLUMN IF EXISTS title_search");

		$columns = ['provider_id', 'document_id', 'owner_id', 'source', 'hash', 'config'];
		$typeResult = $this->db->executeQuery(
			'SELECT attname, format_type(atttypid, atttypmod) AS type'
			. ' FROM pg_catalog.pg_attribute'
			. ' WHERE attrelid = pg_catalog.to_regclass(?) AND attnum > 0 AND NOT attisdropped AND attname = ANY (CAST(? AS text[]))',
			[$qt, PgArray::toLiteral($columns)]
		);
		$currentTypes = [];
		foreach ($typeResult->fetchAll() as $row) {
			$currentTypes[(string)$row['attname']] = (string)$row['type'];
		}
		$typeResult->closeCursor();

		$alterClauses = ["ADD COLUMN IF NOT EXISTS subtags_map jsonb NOT NULL DEFAULT '{}'"];
		foreach ($columns as $col) {
			if (($currentTypes[$col] ?? '') !== 'text') {
				$alterClauses[] = $col === 'config'
					? 'ALTER COLUMN config TYPE text USING config::text'
					: "ALTER COLUMN $col TYPE text";
			}
		}

		$title = $this->normalized('title');
		$alterClauses[] = <<<SQL
			ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
				setweight(to_tsvector({$qfn}(config), $title), 'A')
				|| setweight(to_tsvector({$qfn}(config), {$this->splitWords($title)}), 'A')
				|| setweight(to_tsvector({$qfn}(config), {$this->normalized('tags_text')}), 'B')
				|| setweight(to_tsvector({$qfn}(config), {$this->normalized('parts_text')}), 'C')
				|| setweight(to_tsvector({$qfn}(config), {$this->normalized('content')}), 'D')
			) STORED
			SQL;
		$alterClauses[] = "ADD COLUMN title_search text GENERATED ALWAYS AS (lower($title)) STORED";

		$this->db->executeStatement("ALTER TABLE $qt\n" . implode(",\n", $alterClauses));
		$this->db->executeStatement("COMMENT ON COLUMN $qt.tsv IS '" . self::SCHEMA_MARKER . "'");
	}

	/**
	 * PostgreSQL lowercases with the database locale, which is almost never tr_TR, so Turkish
	 * "I" would become "i" instead of "ı" and uppercase Turkish words would never match.
	 * Map I->ı and İ->i first for Turkish rows; TsQueryBuilder does the same to search terms.
	 */
	public function normalized(string $column): string {
		return "CASE WHEN config = 'turkish' THEN translate($column, 'Iİ', 'ıi') ELSE $column END";
	}

	/**
	 * PostgreSQL's parser reads a file name like "rapor_2025_final.pdf" as one token, so
	 * "final" or "2025" would never find it. Indexing a copy of the title split at
	 * punctuation and at letter/digit boundaries makes each part searchable, while the
	 * unsplit title stays indexed too. TsQueryBuilder::SPLIT_PATTERN mirrors this.
	 */
	public function splitWords(string $expression): string {
		return "regexp_replace(regexp_replace(regexp_replace($expression, "
			. "'([[:alpha:]])([[:digit:]])', '\\1 \\2', 'g'), "
			. "'([[:digit:]])([[:alpha:]])', '\\1 \\2', 'g'), "
			. "'[_.\\-/\\\\+~,;:()\\[\\]{}]+', ' ', 'g')";
	}

	public function dropSchema(): void {
		$qt = $this->getQualifiedTableName();
		$qw = $this->getQualifiedWordsTableName();
		$qfn = $this->getQualifiedRegconfigFunctionName();
		$this->db->executeStatement('DROP TABLE IF EXISTS ' . $qt);
		$this->db->executeStatement('DROP TABLE IF EXISTS ' . $qw);
		$this->db->executeStatement('DROP FUNCTION IF EXISTS ' . $qfn . '(text)');
		$this->schema = null;
	}

	/** Remove every indexed document and the vocabulary, keeping the tables. */
	public function truncate(): void {
		$this->db->executeStatement('TRUNCATE ' . $this->getQualifiedTableName() . ', ' . $this->getQualifiedWordsTableName());
	}

	/** @return array<string, int> language => documents indexed in it */
	public function countDocumentsByLanguage(): array {
		$result = $this->db->executeQuery('SELECT config AS language, count(*) AS documents FROM ' . $this->getQualifiedTableName() . ' GROUP BY config ORDER BY count(*) DESC');
		$counts = [];
		foreach ($result->fetchAll() as $row) {
			$counts[(string)$row['language']] = (int)$row['documents'];
		}
		$result->closeCursor();
		return $counts;
	}

	public function countDocuments(): int {
		$result = $this->db->executeQuery('SELECT count(*) FROM ' . $this->getQualifiedTableName());
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function hasTrigram(): bool {
		if ($this->trigram === null) {
			$result = $this->db->executeQuery(
				"SELECT n.nspname FROM pg_catalog.pg_extension e JOIN pg_catalog.pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_trgm'"
			);
			$nsp = $result->fetchOne();
			$result->closeCursor();
			if (is_string($nsp) && $nsp !== '') {
				$this->trigram = true;
				$this->trigramSchema = $this->quoteIdent($nsp);
			} else {
				$this->trigram = false;
				$this->trigramSchema = null;
			}
		}
		return $this->trigram;
	}

	/** @return string[] text search configurations installed on this server, e.g. english, turkish, simple */
	public function getTextSearchConfigs(): array {
		if ($this->textSearchConfigs === null) {
			$result = $this->db->executeQuery('SELECT cfgname FROM pg_catalog.pg_ts_config ORDER BY cfgname');
			$this->textSearchConfigs = array_map('strval', array_column($result->fetchAll(), 'cfgname'));
			$result->closeCursor();
		}
		return $this->textSearchConfigs;
	}

	/**
	 * Probe whether the database locale supports non-ASCII case folding.
	 * When the database runs under LC_CTYPE="C", lower('Ç') remains 'Ç'.
	 */
	public function hasLocaleCaseFolding(): bool {
		if ($this->localeFolding === null) {
			if (!$this->isPostgres()) {
				return false;
			}
			try {
				$result = $this->db->executeQuery("SELECT lower('Ç') = 'ç'");
				$this->localeFolding = (bool)$result->fetchOne();
				$result->closeCursor();
			} catch (Throwable) {
				return false;
			}
		}
		return $this->localeFolding;
	}

	private function tryCreateTrigramExtension(): void {
		if ($this->hasTrigram() || $this->db->inTransaction()) {
			// A failed statement inside a transaction would abort it, so only try outside one.
			return;
		}
		try {
			// pg_trgm is a "trusted" extension since PostgreSQL 13, so the database owner can create it.
			$currentSchema = $this->db->executeQuery('SELECT pg_catalog.current_schema()')->fetchOne();
			$sql = is_string($currentSchema) && $currentSchema !== ''
				? 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
				: 'CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA ' . $this->quoteIdent($this->getSchemaName());
			$this->db->executeStatement($sql);
			$this->trigram = null;
			$this->trigramSchema = null;
		} catch (Throwable $e) {
			$this->logger->info('pg_trgm extension not available, fuzzy title matching disabled: ' . $e->getMessage());
		}
	}

	/**
	 * Version 1.0.0 tried to create a prefixed table with a tsvector column. If that ever
	 * succeeded it breaks Doctrine schema introspection, and its content is unusable anyway.
	 */
	private function dropLegacyTable(): void {
		$qs = $this->quoteIdent($this->getSchemaName());
		$this->db->executeStatement("DROP TABLE IF EXISTS {$qs}.*PREFIX*fts_pgsql_index");
	}

	private function getSchemaName(): string {
		if ($this->schema !== null) {
			return $this->schema;
		}
		$t = $this->getTableName();
		$result = $this->db->executeQuery(
			"SELECT coalesce(
				(SELECT n.nspname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = pg_catalog.to_regclass(?)),
				(SELECT n.nspname FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
				 WHERE c.relname = ? AND c.relkind IN ('r', 'p')
				   AND n.nspname NOT LIKE 'pg\_%' ESCAPE '\' AND n.nspname <> 'information_schema'
				 ORDER BY (n.nspname = 'public') DESC, n.nspname LIMIT 1)
			) AS found_schema, pg_catalog.current_schema() AS curr_schema",
			[$t, $t]
		);
		$row = $result->fetch();
		$result->closeCursor();
		$found = is_array($row) ? ($row['found_schema'] ?? null) : null;
		if (is_string($found) && $found !== '') {
			return $this->schema = $found;
		}
		$curr = is_array($row) ? ($row['curr_schema'] ?? null) : null;
		return is_string($curr) && $curr !== '' ? $curr : 'public';
	}

	private function quoteIdent(string $ident): string {
		return '"' . str_replace('"', '""', $ident) . '"';
	}
}
