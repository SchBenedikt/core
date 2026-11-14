<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use function in_array;

/**
 * The three attachment families the core stores.
 *
 * A peer renders an image, a video and an audio file differently, and only an
 * image needs a generated thumbnail, so the kind is part of the stored row
 * instead of being re-derived from the MIME type on every read.
 */
enum MediaKind: string
{
	case Image = 'image';

	case Video = 'video';

	case Audio = 'audio';

	public static function fromMimeType(string $mimeType): ?self
	{
		if (in_array($mimeType, Attachment::IMAGE_MIME_TYPES, true)) {
			return self::Image;
		}

		if (in_array($mimeType, Attachment::VIDEO_MIME_TYPES, true)) {
			return self::Video;
		}

		if (in_array($mimeType, Attachment::AUDIO_MIME_TYPES, true)) {
			return self::Audio;
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public function mimeTypes(): array
	{
		return match ($this) {
			self::Image => Attachment::IMAGE_MIME_TYPES,
			self::Video => Attachment::VIDEO_MIME_TYPES,
			self::Audio => Attachment::AUDIO_MIME_TYPES,
		};
	}

	public function maxBytes(): int
	{
		return match ($this) {
			self::Image => Attachment::MAX_BYTES,
			self::Video => Attachment::MAX_VIDEO_BYTES,
			self::Audio => Attachment::MAX_AUDIO_BYTES,
		};
	}

	public function needsThumbnail(): bool
	{
		return $this === self::Image;
	}
}
