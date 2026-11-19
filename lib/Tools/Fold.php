<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Tools;

/**
 * Accent folding for typo correction, so "calisma" can find "çalışma" and "sozlesme" can
 * find "sözleşme". Applied to lowercase words only. The same character lists are used in
 * PHP (strtr) and in SQL (translate) so both sides fold identically.
 */
final class Fold {

	// Each letter of FROM folds to the letter below it in TO.
	public const FROM
		= 'çğıöşüâîûéèêëáàäãåāóòôõōøőúùūñýÿíìïīčšžřďťňęąłżźćń';
	public const TO
		= 'cgiosuaiueeeeaaaaaaooooooouuunyyiiiicszrdtnealzzcn';

	public static function fold(string $word): string {
		return strtr($word, self::map());
	}

	/**
	 * Lowercase UTF-8 text the way PostgreSQL's lower() does outside tr_TR: PHP's mb_strtolower()
	 * turns U+0130 ("İ") into "i\u{0307}", whereas PostgreSQL produces plain "i".
	 */
	public static function lowercase(string $text): string {
		return mb_strtolower(str_replace(['İ', "i\u{0307}"], 'i', $text), 'UTF-8');
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
