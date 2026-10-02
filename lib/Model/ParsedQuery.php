<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Model;

/**
 * A search string compiled to SQL expressions with their bound parameters.
 * Each expression uses positional placeholders; its params are listed in order.
 */
final class ParsedQuery {
	/**
	 * @param string $sql boolean SQL on the table alias "d": which documents match
	 * @param list<string> $params
	 * @param string $rankSql what ts_rank_cd scores against (every positive term)
	 * @param list<string> $rankParams
	 * @param string $allSql boolean SQL on "d": documents containing every positive term, ranked first
	 * @param list<string> $allParams
	 * @param string $plainText the positive search words, for trigram matching
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $params,
		public readonly string $rankSql,
		public readonly array $rankParams,
		public readonly string $allSql,
		public readonly array $allParams,
		public readonly string $plainText,
	) {
	}
}
