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
 * The builder accepts optional replies, facets and embeds. Followers-only and
 * direct visibility have no equivalent in public AT Protocol records, so the
 * publishing service refuses those posts rather than widening their audience.
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
	public static function fromPost(
		Post $post,
		?AtprotoReplyRef $reply = null,
		array $facets = [],
		?array $embed = null,
	): array {
		return self::fromText(
			$post->content,
			$post->publishedAt,
			$post->contentWarning,
			$reply,
			$facets,
			$embed,
			$post->language,
		);
	}

	/**
	 * Builds the record from what a caller actually has: the text, an optional
	 * reply reference and the facets derived from the text.
	 *
	 * @param list<array<string, mixed>> $facets record-ready facet objects; their
	 *                                           offsets are byte offsets in `$text`
	 * @param array<string, mixed>|null  $embed  an `app.bsky.embed.images` or
	 *                                           `app.bsky.embed.video` object
	 *
	 * @return array<string, mixed>
	 */
	public static function fromText(
		string $text,
		DateTimeImmutable $publishedAt,
		?string $contentWarning = null,
		?AtprotoReplyRef $reply = null,
		array $facets = [],
		?array $embed = null,
		?string $language = null,
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

		if ($language !== null) {
			$record['langs'] = [$language];
		}

		if ($reply !== null) {
			$record['reply'] = $reply->toArray();
		}

		if ($facets !== []) {
			$record['facets'] = $facets;
		}

		if ($embed !== null) {
			$record['embed'] = $embed;
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
