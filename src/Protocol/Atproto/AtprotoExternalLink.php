<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Builds a safe, metadata-free Bluesky external-link embed from plain text.
 * No remote page is fetched: publication cannot trigger SSRF or leak a post's
 * contents to an arbitrary link-preview service.
 */
final class AtprotoExternalLink
{
	public const MAX_URI_LENGTH = 2048;

	public const COLLECTION = 'app.bsky.embed.external';

	/**
	 * Return a fallback card only when the post contains exactly one usable URL.
	 * Posts with several links keep their text and facets without an embed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function fromText(string $text): ?array
	{
		$matched = preg_match_all("~https?://[^\\s<>\\\"']+~iu", $text, $matches);
		if ($matched !== 1 || !isset($matches[0][0])) {
			return null;
		}

		$uri = rtrim($matches[0][0], '.,!?;:)]}');
		if ($uri === '' || strlen($uri) > self::MAX_URI_LENGTH || filter_var($uri, FILTER_VALIDATE_URL) === false) {
			return null;
		}

		$parts = parse_url($uri);
		if (!is_array($parts)
			|| !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			|| !isset($parts['host'])
			|| $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
		) {
			return null;
		}

		return [
			'$type' => 'app.bsky.embed.external',
			'external' => [
				'uri' => $uri,
				'title' => (string) $parts['host'],
				'description' => '',
			],
		];
	}
}
