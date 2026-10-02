<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Model;

/**
 * Result of text extraction: the text to index plus an optional warning for the index log.
 */
final class ExtractedContent {
	public function __construct(
		public readonly string $text,
		public readonly ?string $warning = null,
	) {
	}
}
