<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

/**
 * Mastodon token scope matching.
 *
 * A token with `read` grants every `read:*` scope; a granular token does not
 * grant its parent. The exact scope always wins.
 */
final class MastodonScope
{
	/**
	 * @param list<string> $granted
	 */
	public static function allows(array $granted, string $required): bool
	{
		$required = trim($required);
		if ($required === '') {
			return true;
		}

		foreach ($granted as $scope) {
			if ($scope === $required || $scope === '*') {
				return true;
			}

			// Mastodon deprecated `follow` in favour of granular scopes, but
			// existing clients still request it. Keep its relationship-only
			// permissions without making it an alias for all `write:*` routes.
			if ($scope === 'follow' && in_array($required, [
				'read:follows', 'write:follows', 'read:mutes', 'write:mutes',
			], true)) {
				return true;
			}

			$separator = strpos($required, ':');
			if ($separator !== false && substr($required, 0, $separator) === $scope) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $granted
	 */
	public static function allowsAll(array $granted, array $required): bool
	{
		foreach ($required as $scope) {
			if (!self::allows($granted, $scope)) {
				return false;
			}
		}

		return true;
	}
}
