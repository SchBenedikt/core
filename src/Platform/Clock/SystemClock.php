<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock, in UTC.
 */
final class SystemClock implements Clock
{
	public function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
