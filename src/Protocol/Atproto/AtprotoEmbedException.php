<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

/**
 * The requested attachments cannot form one AT Protocol embed.
 *
 * The reason is stable so the publishing service can refuse the post with the
 * same code. Type and size problems are reported as `AtprotoBlobException`;
 * this type covers the embed shape and the alt/aspect rules.
 */
final class AtprotoEmbedException extends RuntimeException
{
	public const MIXED_MEDIA = 'embed_mixed_media';

	public const TOO_MANY_IMAGES = 'embed_too_many_images';

	public const TOO_MANY_VIDEOS = 'embed_too_many_videos';

	public const ALT_MISSING = 'embed_alt_missing';

	public const ALT_TOO_LONG = 'embed_alt_too_long';

	public const ASPECT_RATIO_INVALID = 'embed_aspect_ratio_invalid';

	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
