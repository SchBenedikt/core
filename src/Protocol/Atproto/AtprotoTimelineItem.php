<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use function is_array;
use function is_int;
use function is_string;

/**
 * One post from an AppView timeline.
 *
 * The AppView returns a post view with the author, the engagement counters and
 * an optional embed. The core keeps those fields and nothing else: rendering
 * and identity mapping stay with the host, which owns the local account and
 * the UI. Media inside `embed` stays raw because the AppView already resolved
 * the blob URLs.
 */
final readonly class AtprotoTimelineItem
{
	/**
	 * @param array<string, mixed>|null $embed
	 */
	public function __construct(
		public string $uri,
		public string $cid,
		public string $authorDid,
		public string $authorHandle,
		public ?string $authorDisplayName,
		public string $text,
		public string $createdAt,
		public int $replyCount,
		public int $repostCount,
		public int $likeCount,
		public ?array $embed,
		public ?string $indexedAt = null,
	) {
	}

	/**
	 * Reads one `feed[].post` view; an unusable entry is skipped, not guessed.
	 *
	 * @param array<string, mixed> $view
	 */
	public static function fromView(array $view): ?self
	{
		$uri = $view['uri'] ?? null;
		$cid = $view['cid'] ?? null;
		$author = $view['author'] ?? null;
		$text = $view['text'] ?? '';

		if (!is_string($uri) || !is_string($cid) || !is_array($author)) {
			return null;
		}

		$did = $author['did'] ?? null;
		$handle = $author['handle'] ?? null;
		if (!is_string($did) || !is_string($handle) || $did === '' || $handle === '') {
			return null;
		}

		$displayName = $author['displayName'] ?? null;
		$createdAt = $view['createdAt'] ?? null;
		$indexedAt = $view['indexedAt'] ?? null;
		$embed = $view['embed'] ?? null;

		return new self(
			$uri,
			$cid,
			$did,
			$handle,
			is_string($displayName) ? $displayName : null,
			is_string($text) ? $text : '',
			is_string($createdAt) ? $createdAt : '',
			self::count($view, 'replyCount'),
			self::count($view, 'repostCount'),
			self::count($view, 'likeCount'),
			is_array($embed) ? $embed : null,
			is_string($indexedAt) ? $indexedAt : null,
		);
	}

	/**
	 * @param array<string, mixed> $view
	 */
	private static function count(array $view, string $key): int
	{
		$value = $view[$key] ?? 0;

		return is_int($value) && $value >= 0 ? $value : 0;
	}
}
