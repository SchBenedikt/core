<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * A profile cannot be written to the PDS.
 *
 * The reasons are stable so the host can show them: the profile lexicon only
 * accepts JPEG and PNG images and caps each at one megabyte.
 */
final class AtprotoProfileRejectedException extends RuntimeException
{
	public const MEDIA_UNSUPPORTED = 'profile_media_unsupported';

	public const MEDIA_TOO_LARGE = 'profile_media_too_large';

	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct(match ($reason) {
			self::MEDIA_UNSUPPORTED => 'A profile avatar or banner must be a JPEG or PNG image.',
			self::MEDIA_TOO_LARGE => 'The profile image is larger than one megabyte.',
			default => 'This profile cannot be published to AT Protocol.',
		});
	}
}
