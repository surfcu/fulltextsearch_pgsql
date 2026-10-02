<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Migration;

use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs on install and upgrade. The table is managed with raw SQL instead of a Doctrine
 * migration on purpose; see SchemaService for why.
 */
class CreateIndexTable implements IRepairStep {

	public function __construct(
		private SchemaService $schemaService,
	) {
	}

	public function getName(): string {
		return 'Create the PostgreSQL full text search index table';
	}

	public function run(IOutput $output): void {
		if (!$this->schemaService->isPostgres()) {
			$output->warning('Nextcloud is not running on PostgreSQL; the PostgreSQL full text search platform cannot be used.');
			return;
		}
		$this->schemaService->ensureSchema();
		if (!$this->schemaService->hasTrigram()) {
			$output->info('pg_trgm is not installed: typo-tolerant title matching is disabled. A superuser can run CREATE EXTENSION pg_trgm; to enable it.');
		}
	}
}
