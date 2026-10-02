<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Service;

use OCP\FullTextSearch\Model\IDocumentAccess;

/**
 * Access control is stored as one text[] column of prefixed tokens so a single GIN-indexed
 * overlap test (`access && viewer_tokens`) decides visibility:
 *   u:<uid>     owner, or a user the document is shared with ("u:__all" = everyone)
 *   g:<gid>     a group the document is shared with
 *   c:<circle>  a circle/team the document is shared with
 * Share links are not access grants for searching and are stored separately.
 */
final class AccessTokens {

	public const ALL_USERS = '__all';

	/** @return list<string> */
	public static function forDocument(IDocumentAccess $access): array {
		$tokens = [];
		if ($access->getOwnerId() !== '') {
			$tokens[] = 'u:' . $access->getOwnerId();
		}
		foreach ($access->getUsers() as $user) {
			$tokens[] = 'u:' . $user;
		}
		foreach ($access->getGroups() as $group) {
			$tokens[] = 'g:' . $group;
		}
		foreach ($access->getCircles() as $circle) {
			$tokens[] = 'c:' . $circle;
		}
		return array_values(array_unique(array_map('strval', $tokens)));
	}

	/**
	 * Tokens that grant the searching user access. The framework fills the viewer's groups
	 * and circles in the IDocumentAccess it passes to searchRequest().
	 *
	 * @return list<string>
	 */
	public static function forViewer(IDocumentAccess $access): array {
		$viewer = $access->getViewerId();
		if ($viewer === '') {
			return [];
		}
		$tokens = ['u:' . $viewer, 'u:' . self::ALL_USERS];
		foreach ($access->getGroups() as $group) {
			$tokens[] = 'g:' . $group;
		}
		foreach ($access->getCircles() as $circle) {
			$tokens[] = 'c:' . $circle;
		}
		return array_values(array_unique(array_map('strval', $tokens)));
	}

	/**
	 * @param list<string> $tokens
	 * @return array{users: list<string>, groups: list<string>, circles: list<string>}
	 */
	public static function split(array $tokens, string $ownerId): array {
		$out = ['users' => [], 'groups' => [], 'circles' => []];
		foreach ($tokens as $token) {
			[$type, $id] = array_pad(explode(':', $token, 2), 2, '');
			match ($type) {
				'u' => $id !== $ownerId ? $out['users'][] = $id : null,
				'g' => $out['groups'][] = $id,
				'c' => $out['circles'][] = $id,
				default => null,
			};
		}
		return $out;
	}
}
