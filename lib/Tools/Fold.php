<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Tools;

/**
 * Accent folding for typo correction, so "calisma" can find "çalışma" and "sozlesme" can
 * find "sözleşme". Applied to lowercase words only. The same character lists are used in
 * PHP (strtr) and in SQL (translate) so both sides fold identically.
 */
final class Fold {

	public const FROM = 'çğıöşüâîûéèêëáàäãåāóòôõōøőúùūñýÿíìïīčšžřďťňęąłżźćń';
	public const TO   = 'cgiosuaiueeeeaaaaaaooooooouuunyyiiiicszrdtnealzzcn';

	public static function fold(string $word): string {
		return strtr($word, self::map());
	}

	/** @return array<string, string> */
	private static function map(): array {
		static $map = null;
		if ($map === null) {
			$map = array_combine(mb_str_split(self::FROM), mb_str_split(self::TO));
		}
		return $map;
	}
}
