<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

/**
 * An AppView timeline could not be read.
 *
 * `reason` is stable: a host maps `timeline_unavailable` to a temporary
 * failure and `timeline_invalid_response` to a protocol error, instead of
 * showing an empty timeline for a failed request.
 */
final class AtprotoTimelineException extends RuntimeException
{
	public const UNAVAILABLE = 'timeline_unavailable';

	public const INVALID_RESPONSE = 'timeline_invalid_response';

	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
