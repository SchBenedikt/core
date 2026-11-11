<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Clock;

use DateTimeImmutable;

/**
 * Source of the current time for everything in the core.
 *
 * A port rather than `new DateTimeImmutable()` so that retry backoff, token
 * expiry and "published at" can be tested without sleeping.
 */
interface Clock
{
	/**
	 * The current instant, always in UTC.
	 *
	 * Implementations must not return the server's local timezone: every
	 * timestamp the core stores or federates is UTC.
	 */
	public function now(): DateTimeImmutable;
}
