<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;

/**
 * An uploaded image.
 *
 * The domain is deliberately narrow: one media kind, three web-safe image
 * formats, no SVG (scriptable), no animated GIF (a thumbnail would silently
 * change the content). Bytes are validated by the media service before an
 * instance is created; this object validates the metadata invariants that
 * every adapter must preserve.
 */
final readonly class Attachment
{
	public const MEDIA_TYPE_IMAGE = 'image';

	public const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
	];

	public const EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
	];

	public const MAX_BYTES = 5 * 1024 * 1024;

	public const MAX_ALT_TEXT_LENGTH = 1000;

	public const MAX_DIMENSION = 10000;

	public const MAX_PIXELS = 40_000_000;

	public const MAX_SOURCE_URL_LENGTH = 512;

	public function __construct(
		public string $id,
		public string $ownerId,
		public string $storageKey,
		public string $thumbnailKey,
		public string $mimeType,
		public int $width,
		public int $height,
		public int $byteSize,
		public ?string $altText,
		public DateTimeImmutable $createdAt,
		public ?string $sourceUrl = null,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('An attachment needs an id of at most 64 characters.');
		}

		if ($ownerId === '' || mb_strlen($ownerId) > 255) {
			throw new InvalidArgumentException('An attachment needs an owner.');
		}

		if ($storageKey === '' || mb_strlen($storageKey) > 512
			|| $thumbnailKey === '' || mb_strlen($thumbnailKey) > 512
		) {
			throw new InvalidArgumentException('An attachment needs two storage keys of at most 512 characters.');
		}

		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			throw new InvalidArgumentException('Unsupported attachment MIME type: ' . $mimeType);
		}

		if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
			throw new InvalidArgumentException('An attachment has invalid dimensions.');
		}

		if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
			throw new InvalidArgumentException('An attachment has an invalid size.');
		}

		if ($altText !== null && mb_strlen($altText) > self::MAX_ALT_TEXT_LENGTH) {
			throw new InvalidArgumentException('An attachment alt text is too long.');
		}

		if ($sourceUrl !== null && ($sourceUrl === '' || mb_strlen($sourceUrl) > self::MAX_SOURCE_URL_LENGTH)) {
			throw new InvalidArgumentException('An attachment source URL is invalid.');
		}
	}

	public function extension(): string
	{
		return self::EXTENSIONS[$this->mimeType];
	}

	/**
	 * The metadata shape. URLs are added by the media service because only it
	 * knows the instance base URL.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'media_type' => self::MEDIA_TYPE_IMAGE,
			'mime_type' => $this->mimeType,
			'width' => $this->width,
			'height' => $this->height,
			'byte_size' => $this->byteSize,
			'alt_text' => $this->altText,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
