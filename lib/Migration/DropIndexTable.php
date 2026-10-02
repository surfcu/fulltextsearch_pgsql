<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Migration;

use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs when the app is removed.
 */
class DropIndexTable implements IRepairStep {

	public function __construct(
		private SchemaService $schemaService,
	) {
	}

	public function getName(): string {
		return 'Drop the PostgreSQL full text search index table';
	}

	public function run(IOutput $output): void {
		if ($this->schemaService->isPostgres()) {
			$this->schemaService->dropSchema();
		}
	}
}
