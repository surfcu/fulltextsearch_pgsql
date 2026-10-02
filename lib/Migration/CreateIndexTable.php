<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Migration;

use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\VocabularyService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Runs on install and upgrade. The table is managed with raw SQL instead of a Doctrine
 * migration on purpose; see SchemaService for why.
 */
class CreateIndexTable implements IRepairStep {

	/** Above this many documents the vocabulary is left for the admin to build with occ. */
	private const AUTO_VOCABULARY_LIMIT = 10000;

	public function __construct(
		private SchemaService $schemaService,
		private VocabularyService $vocabulary,
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
		$this->fillVocabulary($output);
		if (!$this->schemaService->hasTrigram()) {
			$output->info('pg_trgm is not installed: typo-tolerant title matching is disabled. A superuser can run CREATE EXTENSION pg_trgm; to enable it.');
		}
	}

	/**
	 * Indexes created before typo correction existed have an empty vocabulary. Fill it on
	 * upgrade for small instances; larger ones get a hint to run it at a convenient time.
	 */
	private function fillVocabulary(IOutput $output): void {
		if ($this->vocabulary->count() > 0) {
			return;
		}
		$documents = $this->schemaService->countDocuments();
		if ($documents === 0) {
			return;
		}
		if ($documents > self::AUTO_VOCABULARY_LIMIT) {
			$output->info("Typo correction needs a word list for the $documents indexed documents. Build it with: occ fulltextsearch_pgsql:vocabulary --rebuild");
			return;
		}
		$output->startProgress($documents);
		$last = 0;
		$this->vocabulary->rebuild(function (int $done) use ($output, &$last): void {
			$output->advance($done - $last);
			$last = $done;
		});
		$output->finishProgress();
	}
}
