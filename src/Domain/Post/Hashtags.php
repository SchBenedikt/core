<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use function array_keys;
use function count;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function preg_match_all;
use function substr;
use function trim;

/**
 * Hashtags in plain-text post content.
 *
 * A tag starts with `#` at a word boundary and contains letters, digits and
 * underscores. Matching is Unicode-aware but deliberately does not try to
 * parse URLs or HTML: posts in the core are plain text, and a tag inside a
 * link is still a tag the author wrote.
 */
final class Hashtags
{
	public const MAX_LENGTH = 100;

	public const MAX_PER_POST = 50;

	/**
	 * @return list<string> normalized tags, first occurrence first
	 */
	public static function extract(string $content): array
	{
		$matched = preg_match_all(
			'/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{1,' . self::MAX_LENGTH . '})/u',
			$content,
			$matches,
		);

		if ($matched === false || $matched === 0) {
			return [];
		}

		$tags = [];
		foreach ($matches[1] as $tag) {
			$normalized = mb_strtolower($tag);
			if ($normalized === '' || isset($tags[$normalized])) {
				continue;
			}

			$tags[$normalized] = true;
			if (count($tags) >= self::MAX_PER_POST) {
				break;
			}
		}

		return array_keys($tags);
	}

	/**
	 * Normalizes a route or query tag. Returns null for an empty or malformed
	 * tag instead of guessing at a different spelling.
	 */
	public static function normalize(string $tag): ?string
	{
		$tag = trim($tag);
		if ($tag !== '' && $tag[0] === '#') {
			$tag = trim(substr($tag, 1));
		}

		$tag = mb_strtolower($tag);
		if ($tag === '' || mb_strlen($tag) > self::MAX_LENGTH) {
			return null;
		}

		return preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1 ? $tag : null;
	}
}
