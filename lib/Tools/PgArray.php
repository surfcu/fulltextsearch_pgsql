<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Tools;

/**
 * Builds PostgreSQL array literals for binding as a single parameter (cast with ::text[]).
 */
final class PgArray {

	/** @param list<string> $values */
	public static function toLiteral(array $values): string {
		$items = array_map(
			static fn (string $v): string => '"' . addcslashes(str_replace("\0", '', $v), '"\\') . '"',
			$values
		);
		return '{' . implode(',', $items) . '}';
	}
}
