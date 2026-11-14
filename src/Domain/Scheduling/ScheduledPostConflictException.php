<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use RuntimeException;

/**
 * A reschedule or cancel that lost against the publisher.
 *
 * The row stopped being `scheduled`, so the request cannot be applied without
 * reviving a publication that already started.
 */
final class ScheduledPostConflictException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('The scheduled post ' . $id . ' can no longer be changed.');
	}
}
