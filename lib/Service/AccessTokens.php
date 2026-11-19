<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCP\FullTextSearch\Model\IDocumentAccess;

/**
 * Access control is stored as one text[] column of prefixed tokens so a single GIN-indexed
 * overlap test (`access && viewer_tokens`) decides visibility:
 *   u:<uid>     owner, or a user the document is shared with ("u:__all" = everyone)
 *   o:__all     owner literally named "__all", so private files of that account stay private
 *   g:<gid>     a group the document is shared with
 *   c:<circle>  a circle/team the document is shared with
 * Share links are not access grants for searching and are stored separately.
 */
final class AccessTokens {

	public const ALL_USERS = '__all';

	/** @return list<string> */
	public static function forDocument(IDocumentAccess $access): array {
		$tokens = [];
		$owner = $access->getOwnerId();
		if (self::isValidPrincipal($owner)) {
			$tokens[] = ($owner === self::ALL_USERS ? 'o:' : 'u:') . $owner;
		}
		foreach ($access->getUsers() as $user) {
			$u = (string)$user;
			if (self::isValidPrincipal($u)) {
				$tokens[] = 'u:' . $u;
			}
		}
		foreach ($access->getGroups() as $group) {
			$g = (string)$group;
			if (self::isValidPrincipal($g)) {
				$tokens[] = 'g:' . $g;
			}
		}
		foreach ($access->getCircles() as $circle) {
			$c = (string)$circle;
			if (self::isValidPrincipal($c)) {
				$tokens[] = 'c:' . $c;
			}
		}
		return array_values(array_unique($tokens));
	}

	/**
	 * Tokens that grant the searching user access. The framework fills the viewer's groups
	 * and circles in the IDocumentAccess it passes to searchRequest().
	 *
	 * @return list<string>
	 */
	public static function forViewer(IDocumentAccess $access): array {
		$viewer = $access->getViewerId();
		if (!self::isValidPrincipal($viewer)) {
			return [];
		}
		$tokens = $viewer === self::ALL_USERS
			? ['o:' . self::ALL_USERS, 'u:' . self::ALL_USERS]
			: ['u:' . $viewer, 'u:' . self::ALL_USERS];
		foreach ($access->getGroups() as $group) {
			$g = (string)$group;
			if (self::isValidPrincipal($g)) {
				$tokens[] = 'g:' . $g;
			}
		}
		foreach ($access->getCircles() as $circle) {
			$c = (string)$circle;
			if (self::isValidPrincipal($c)) {
				$tokens[] = 'c:' . $c;
			}
		}
		return array_values(array_unique($tokens));
	}

	/**
	 * @param list<string> $tokens
	 * @return array{users: list<string>, groups: list<string>, circles: list<string>}
	 */
	public static function split(array $tokens, string $ownerId): array {
		$out = ['users' => [], 'groups' => [], 'circles' => []];
		$hasOwnerAllToken = $ownerId === self::ALL_USERS && in_array('o:' . self::ALL_USERS, $tokens, true);
		foreach ($tokens as $token) {
			[$type, $id] = array_pad(explode(':', $token, 2), 2, '');
			match ($type) {
				'u' => ($id !== $ownerId || $hasOwnerAllToken) ? $out['users'][] = $id : null,
				'g' => $out['groups'][] = $id,
				'c' => $out['circles'][] = $id,
				default => null,
			};
		}
		return $out;
	}

	private static function isValidPrincipal(string $id): bool {
		return $id !== '' && !str_contains($id, "\0") && mb_check_encoding($id, 'UTF-8');
	}
}
