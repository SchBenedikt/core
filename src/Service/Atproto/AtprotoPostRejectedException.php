<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * The local post cannot be represented as a public AT Protocol post.
 *
 * The reason is a stable machine-readable value so API clients can explain
 * why a post was skipped while still receiving a useful message.
 */
final class AtprotoPostRejectedException extends RuntimeException
{
	public const NOT_PUBLIC = 'not_public';

	public const ATTACHMENTS_UNSUPPORTED = 'attachments_unsupported';

	public const TEXT_TOO_LONG = 'text_too_long';

	public function __construct(
		public readonly string $reason,
	) {
		$message = match ($reason) {
			self::NOT_PUBLIC => 'Only public posts can be published to AT Protocol.',
			self::ATTACHMENTS_UNSUPPORTED => 'Posts with attachments cannot be published to AT Protocol yet.',
			self::TEXT_TOO_LONG => 'The post text is too long for AT Protocol.',
			default => 'This post cannot be published to AT Protocol.',
		};

		parent::__construct($message);
	}
}
