<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use Throwable;

/**
 * What the admin panel shows next to the settings: whether the database and the optional
 * tools are there, and how big the index is. Every probe is cheap and never throws.
 */
class StatusService {

	public function __construct(
		private SchemaService $schemaService,
		private VocabularyService $vocabularyService,
		private ContentExtractor $contentExtractor,
	) {
	}

	/**
	 * @return array{postgres: bool, table: string, documents: ?int, words: ?int, pg_trgm: bool,
	 *               pdftotext: ?string, pdfparser: bool, pdf_tool: ?string}
	 */
	public function getStatus(): array {
		$postgres = $this->schemaService->isPostgres();
		return [
			'postgres' => $postgres,
			'table' => $this->schemaService->getTableName(),
			'documents' => $postgres ? $this->attempt(fn () => $this->schemaService->countDocuments()) : null,
			'words' => $postgres ? $this->attempt(fn () => $this->vocabularyService->count()) : null,
			'pg_trgm' => $postgres && (bool)$this->attempt(fn () => $this->schemaService->hasTrigram()),
			'pdftotext' => $this->contentExtractor->findPdfToTextPath(),
			'pdfparser' => $this->contentExtractor->pdfParserAvailable(),
			'pdf_tool' => $this->contentExtractor->pdfTool(),
		];
	}

	/**
	 * @template T
	 * @param callable(): T $probe
	 * @return T|null
	 */
	private function attempt(callable $probe): mixed {
		try {
			return $probe();
		} catch (Throwable) {
			// Table not created yet, or the database refused: the panel shows "unknown".
			return null;
		}
	}
}
