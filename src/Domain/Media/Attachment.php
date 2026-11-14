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
 * An uploaded image, video or audio file.
 *
 * The bytes are validated by the media service before an instance is created;
 * this object enforces the metadata invariants every adapter must preserve.
 * SVG and HTML stay outside the allowed image types because both can execute
 * script in a browser.
 *
 * Video and audio carry no generated thumbnail (a host transcoder may supply
 * a poster later), so their thumbnail key is allowed to be empty. A still
 * image always has one.
 */
final readonly class Attachment
{
	public const IMAGE_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
	];

	public const VIDEO_MIME_TYPES = [
		'video/mp4',
		'video/webm',
	];

	public const AUDIO_MIME_TYPES = [
		'audio/mpeg',
		'audio/mp4',
		'audio/ogg',
		'audio/wav',
	];

	public const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'video/mp4',
		'video/webm',
		'audio/mpeg',
		'audio/mp4',
		'audio/ogg',
		'audio/wav',
	];

	public const EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
		'video/mp4' => 'mp4',
		'video/webm' => 'webm',
		'audio/mpeg' => 'mp3',
		'audio/mp4' => 'm4a',
		'audio/ogg' => 'ogg',
		'audio/wav' => 'wav',
	];

	/** The image limit, kept as `MAX_BYTES` for existing configuration. */
	public const MAX_BYTES = 5 * 1024 * 1024;

	public const MAX_VIDEO_BYTES = 20 * 1024 * 1024;

	public const MAX_AUDIO_BYTES = 10 * 1024 * 1024;

	public const MAX_REQUEST_BYTES = 25 * 1024 * 1024;

	public const MAX_ALT_TEXT_LENGTH = 1000;

	public const MAX_DIMENSION = 10000;

	public const MAX_PIXELS = 40_000_000;

	public const MAX_DURATION_SECONDS = 14_400;

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
		public MediaKind $kind = MediaKind::Image,
		public int $durationSeconds = 0,
		public bool $animated = false,
		public ?float $focusX = null,
		public ?float $focusY = null,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('An attachment needs an id of at most 64 characters.');
		}

		if ($ownerId === '' || mb_strlen($ownerId) > 255) {
			throw new InvalidArgumentException('An attachment needs an owner.');
		}

		if ($storageKey === '' || mb_strlen($storageKey) > 512) {
			throw new InvalidArgumentException('An attachment needs a storage key of at most 512 characters.');
		}

		if (mb_strlen($thumbnailKey) > 512) {
			throw new InvalidArgumentException('An attachment thumbnail key is too long.');
		}

		if (!in_array($mimeType, $kind->mimeTypes(), true)) {
			throw new InvalidArgumentException('Unsupported attachment MIME type for ' . $kind->value . ': ' . $mimeType);
		}

		if ($kind->needsThumbnail() && $thumbnailKey === '') {
			throw new InvalidArgumentException('An image attachment needs a thumbnail key.');
		}

		$minimumDimension = $kind === MediaKind::Image ? 1 : 0;
		if ($width < $minimumDimension || $height < $minimumDimension
			|| $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
			|| $width * $height > self::MAX_PIXELS
		) {
			throw new InvalidArgumentException('An attachment has invalid dimensions.');
		}

		if ($byteSize < 1 || $byteSize > $kind->maxBytes()) {
			throw new InvalidArgumentException('An attachment has an invalid size.');
		}

		if ($durationSeconds < 0 || $durationSeconds > self::MAX_DURATION_SECONDS) {
			throw new InvalidArgumentException('An attachment has an invalid duration.');
		}

		if ($animated && $kind !== MediaKind::Image) {
			throw new InvalidArgumentException('Only an image can be animated.');
		}

		if (($focusX === null) !== ($focusY === null)) {
			throw new InvalidArgumentException('An attachment focus needs both coordinates.');
		}

		if ($focusX !== null && ($focusX < -1.0 || $focusX > 1.0 || $focusY < -1.0 || $focusY > 1.0)) {
			throw new InvalidArgumentException('An attachment focus is outside the image.');
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
	 * Returns the same attachment with a new description. Metadata updates
	 * must not replace the bytes or storage keys.
	 */
	public function withAltText(?string $altText): self
	{
		return new self(
			id: $this->id,
			ownerId: $this->ownerId,
			storageKey: $this->storageKey,
			thumbnailKey: $this->thumbnailKey,
			mimeType: $this->mimeType,
			width: $this->width,
			height: $this->height,
			byteSize: $this->byteSize,
			altText: $altText,
			createdAt: $this->createdAt,
			sourceUrl: $this->sourceUrl,
			kind: $this->kind,
			durationSeconds: $this->durationSeconds,
			animated: $this->animated,
			focusX: $this->focusX,
			focusY: $this->focusY,
		);
	}

	/**
	 * A focal point is normalized to -1..1 by the service; both values travel
	 * together so a crop cannot use a stale half of a previous pair.
	 */
	public function withFocus(?float $focusX, ?float $focusY): self
	{
		return new self(
			id: $this->id,
			ownerId: $this->ownerId,
			storageKey: $this->storageKey,
			thumbnailKey: $this->thumbnailKey,
			mimeType: $this->mimeType,
			width: $this->width,
			height: $this->height,
			byteSize: $this->byteSize,
			altText: $this->altText,
			createdAt: $this->createdAt,
			sourceUrl: $this->sourceUrl,
			kind: $this->kind,
			durationSeconds: $this->durationSeconds,
			animated: $this->animated,
			focusX: $focusX,
			focusY: $focusY,
		);
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
			'media_type' => $this->kind === MediaKind::Image && $this->animated ? 'gifv' : $this->kind->value,
			'mime_type' => $this->mimeType,
			'width' => $this->width,
			'height' => $this->height,
			'byte_size' => $this->byteSize,
			'alt_text' => $this->altText,
			'duration_seconds' => $this->durationSeconds,
			'animated' => $this->animated,
			'focus_x' => $this->focusX,
			'focus_y' => $this->focusY,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
