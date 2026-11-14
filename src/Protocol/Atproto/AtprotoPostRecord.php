<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The `app.bsky.feed.post` record built from a local post.
 *
 * Only text is represented in this first slice. Images would need a blob
 * upload and an embed, and followers-only/direct visibility has no equivalent
 * in the public AT Protocol record, so the service refuses those posts rather
 * than silently dropping part of them.
 */
final class AtprotoPostRecord
{
	public const COLLECTION = 'app.bsky.feed.post';

	public const MAX_TEXT_GRAPHEMES = 300;

	public const MAX_TEXT_BYTES = 3000;

	/**
	 * @param list<array<string, mixed>> $facets
	 *
	 * @return array<string, mixed>
	 */
	public static function fromPost(Post $post, ?AtprotoReplyRef $reply = null, array $facets = []): array
	{
		return self::fromText($post->content, $post->publishedAt, $post->contentWarning, $reply, $facets);
	}

	/**
	 * Builds the record from what a caller actually has: the text, an optional
	 * reply reference and the facets derived from the text.
	 *
	 * @param list<array<string, mixed>> $facets record-ready facet objects; their
	 *                                           offsets are byte offsets in `$text`
	 *
	 * @return array<string, mixed>
	 */
	public static function fromText(
		string $text,
		DateTimeImmutable $publishedAt,
		?string $contentWarning = null,
		?AtprotoReplyRef $reply = null,
		array $facets = [],
	): array {
		$record = [
			'$type' => self::COLLECTION,
			'text' => $text,
			'createdAt' => Time::format($publishedAt),
		];

		if ($contentWarning !== null) {
			$record['labels'] = [
				'$type' => 'com.atproto.label.defs#selfLabels',
				'values' => [
					['val' => 'content-warning'],
				],
			];
		}

		if ($reply !== null) {
			$record['reply'] = $reply->toArray();
		}

		if ($facets !== []) {
			$record['facets'] = $facets;
		}

		return $record;
	}

	public static function acceptsText(string $text): bool
	{
		return mb_strlen($text) <= self::MAX_TEXT_GRAPHEMES
			&& strlen($text) <= self::MAX_TEXT_BYTES;
	}

	/**
	 * @throws InvalidArgumentException when the post has no representable text
	 */
	public static function assertRepresentable(Post $post): void
	{
		if (!self::acceptsText($post->content)) {
			throw new InvalidArgumentException('The post text does not fit the AT Protocol post limits.');
		}
	}
}
