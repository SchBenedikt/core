<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;

use function array_is_list;
use function count;
use function filter_var;
use function is_array;
use function in_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function strlen;
use function parse_url;
use function preg_match;
use function strtolower;
use function trim;

/**
 * A readable projection of one `app.bsky.feed.post` record.
 *
 * This is a read model, not the canonical AT repository object: the original
 * record stays opaque in the store, while this projection exposes the text,
 * reply references and media that a host can render. An unsupported or
 * malformed embed never becomes a guessed URL; the item is skipped instead.
 */
final readonly class AtprotoPostProjection
{
	public const MAX_TEXT_GRAPHEMES = 300;

	public const MAX_TEXT_BYTES = 3000;

	public const MAX_IMAGES = 4;

	public const MAX_ALT_LENGTH = 2000;

	public const MAX_EXTERNAL_TITLE = 300;

	public const MAX_EXTERNAL_DESCRIPTION = 1000;

	public const MAX_DIMENSION = 10000;

	public const COLLECTION = 'app.bsky.feed.post';

	/**
	 * @param list<array{url: ?string, cid: string, alt: string, width: ?int, height: ?int}> $images
	 * @param array{uri: string, title: string, description: ?string, thumb_url: ?string}|null $external
	 */
	public function __construct(
		public string $uri,
		public string $cid,
		public string $did,
		public ?string $handle,
		public string $text,
		public DateTimeImmutable $createdAt,
		public ?string $replyToUri,
		public ?string $rootUri,
		public array $images,
		public ?array $external,
	) {
	}

	/**
	 * @param AtprotoRemoteRecord $record a live record from the sync store
	 */
	public static function fromRecord(
		AtprotoRemoteRecord $record,
		?AtprotoBlobUrlBuilder $blobs = null,
		?string $handle = null,
	): ?self {
		if ($record->deleted
			|| $record->collection !== self::COLLECTION
			|| $record->cid === null
			|| $record->cid === ''
		) {
			return null;
		}

		$text = $record->record['text'] ?? null;
		$createdAt = $record->record['createdAt'] ?? null;
		if (!is_string($text)
			|| mb_strlen($text) > self::MAX_TEXT_GRAPHEMES
			|| strlen($text) > self::MAX_TEXT_BYTES
			|| !is_string($createdAt)
		) {
			return null;
		}

		$publishedAt = self::timestamp($createdAt);
		if ($publishedAt === null) {
			return null;
		}

		$replyTo = null;
		$root = null;
		$reply = $record->record['reply'] ?? null;
		if ($reply !== null) {
			if (!is_array($reply)) {
				return null;
			}

			$parent = $reply['parent'] ?? null;
			$rootRef = $reply['root'] ?? null;
			$replyTo = self::referenceUri($parent);
			$root = self::referenceUri($rootRef);
			if ($replyTo === null || $root === null) {
				return null;
			}
		}

		$images = [];
		$external = null;
		$embed = $record->record['embed'] ?? null;
		if ($embed !== null) {
			if (!is_array($embed)) {
				return null;
			}

			$type = $embed['$type'] ?? null;
			if ($type === 'app.bsky.embed.images') {
				$images = self::images($embed, $record->did, $blobs);
				if ($images === null) {
					return null;
				}
			} elseif ($type === 'app.bsky.embed.external') {
				$external = self::external($embed, $record->did, $blobs);
				if ($external === null) {
					return null;
				}
			} elseif ($type === 'app.bsky.embed.video') {
				$video = self::video($embed, $record->did, $blobs);
				if ($video === null) {
					return null;
				}

				$images = [$video];
			}
		}

		if ($text === '' && $images === [] && $external === null) {
			return null;
		}

		return new self(
			$record->uri,
			$record->cid,
			$record->did,
			$handle,
			$text,
			$publishedAt,
			$replyTo,
			$root,
			$images,
			$external,
		);
	}

	/**
	 * @param array<string, mixed> $embed
	 *
	 * @return list<array{url: ?string, cid: string, alt: string, width: ?int, height: ?int}>|null
	 */
	private static function images(
		array $embed,
		string $did,
		?AtprotoBlobUrlBuilder $blobs,
	): ?array {
		$raw = $embed['images'] ?? null;
		if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_IMAGES) {
			return null;
		}

		$images = [];
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				return null;
			}

			$alt = $entry['alt'] ?? null;
			$cid = self::blobCid($entry['image'] ?? null);
			if (!is_string($alt) || mb_strlen($alt) > self::MAX_ALT_LENGTH || $cid === null) {
				return null;
			}

			$aspect = self::aspectRatio($entry['aspectRatio'] ?? null);
			if ($aspect === false) {
				return null;
			}

			$images[] = [
				'url' => $blobs?->url($did, $cid),
				'cid' => $cid,
				'alt' => $alt,
				'width' => $aspect['width'] ?? null,
				'height' => $aspect['height'] ?? null,
			];
		}

		return $images;
	}

	/**
	 * @param array<string, mixed> $embed
	 *
	 * @return array{url: ?string, cid: string, alt: string, width: ?int, height: ?int}|null
	 */
	private static function video(
		array $embed,
		string $did,
		?AtprotoBlobUrlBuilder $blobs,
	): ?array {
		$cid = self::blobCid($embed['video'] ?? null);
		$alt = $embed['alt'] ?? '';
		if ($cid === null || !is_string($alt) || mb_strlen($alt) > self::MAX_ALT_LENGTH) {
			return null;
		}

		$aspect = self::aspectRatio($embed['aspectRatio'] ?? null);
		if ($aspect === false) {
			return null;
		}

		return [
			'url' => $blobs?->url($did, $cid),
			'cid' => $cid,
			'alt' => $alt,
			'width' => $aspect['width'] ?? null,
			'height' => $aspect['height'] ?? null,
		];
	}

	/**
	 * @param array<string, mixed> $embed
	 *
	 * @return array{uri: string, title: string, description: ?string, thumb_url: ?string}|null
	 */
	private static function external(
		array $embed,
		string $did,
		?AtprotoBlobUrlBuilder $blobs,
	): ?array {
		$external = $embed['external'] ?? null;
		if (!is_array($external)) {
			return null;
		}

		$uri = $external['uri'] ?? null;
		$title = $external['title'] ?? null;
		$description = $external['description'] ?? null;
		if (!is_string($uri) || !self::isHttpUrl($uri)
			|| !is_string($title) || trim($title) === ''
			|| mb_strlen($title) > self::MAX_EXTERNAL_TITLE
		) {
			return null;
		}

		if ($description !== null
			&& (!is_string($description) || mb_strlen($description) > self::MAX_EXTERNAL_DESCRIPTION)
		) {
			return null;
		}

		$thumbCid = self::blobCid($external['thumb'] ?? null);

		return [
			'uri' => $uri,
			'title' => $title,
			'description' => $description,
			'thumb_url' => $thumbCid === null ? null : $blobs?->url($did, $thumbCid),
		];
	}

	/**
	 * @return array{width: int, height: int}|null|false `false` for an invalid shape
	 */
	private static function aspectRatio(mixed $value): array|null|false
	{
		if ($value === null) {
			return null;
		}

		if (!is_array($value)) {
			return false;
		}

		$width = $value['width'] ?? null;
		$height = $value['height'] ?? null;
		if (!is_int($width) || !is_int($height)
			|| $width < 1 || $height < 1
			|| $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
		) {
			return false;
		}

		return ['width' => $width, 'height' => $height];
	}

	private static function blobCid(mixed $blob): ?string
	{
		if (!is_array($blob)) {
			return null;
		}

		$ref = $blob['ref'] ?? null;
		$cid = is_array($ref) ? ($ref['$link'] ?? null) : $ref;
		if (!is_string($cid) || $cid === '' || mb_strlen($cid) > 256 || trim($cid) !== $cid) {
			return null;
		}

		return $cid;
	}

	private static function referenceUri(mixed $reference): ?string
	{
		if (!is_array($reference)) {
			return null;
		}

		$uri = $reference['uri'] ?? null;

		return AtprotoIdentifiers::isAtUri($uri) ? trim($uri) : null;
	}

	private static function timestamp(string $value): ?DateTimeImmutable
	{
		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			return null;
		}

		try {
			return Time::toSecondPrecision(new DateTimeImmutable($value));
		} catch (Exception) {
			return null;
		}
	}

	private static function isHttpUrl(string $value): bool
	{
		$scheme = parse_url($value, PHP_URL_SCHEME);
		$host = parse_url($value, PHP_URL_HOST);

		return filter_var($value, FILTER_VALIDATE_URL) !== false
			&& is_string($scheme)
			&& is_string($host)
			&& $host !== ''
			&& in_array(strtolower($scheme), ['http', 'https'], true);
	}
}
