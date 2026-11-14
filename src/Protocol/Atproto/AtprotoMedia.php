<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use function strlen;

/**
 * One image or video a caller wants to attach to a published post.
 *
 * This is raw input: the bytes, their media type and the optional description.
 * `AtprotoEmbed` validates type, size, alt text and aspect ratio before it
 * uploads anything, so an invalid attachment never becomes a partial record.
 */
final readonly class AtprotoMedia
{
	/**
	 * @param string      $mimeType the media type sent as the upload Content-Type
	 * @param string      $bytes    the raw file
	 * @param string|null $alt      the description; required for an image (an
	 *                              empty string is allowed and means decorative)
	 * @param int|null    $width    pixel width, together with `$height`
	 * @param int|null    $height   pixel height, together with `$width`
	 */
	public function __construct(
		public string $mimeType,
		public string $bytes,
		public ?string $alt = null,
		public ?int $width = null,
		public ?int $height = null,
	) {
	}

	public function isImage(): bool
	{
		return str_starts_with($this->mimeType, 'image/');
	}

	public function isVideo(): bool
	{
		return str_starts_with($this->mimeType, 'video/');
	}

	public function size(): int
	{
		return strlen($this->bytes);
	}
}
