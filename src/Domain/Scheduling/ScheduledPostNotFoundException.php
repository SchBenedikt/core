<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use RuntimeException;

/**
 * A scheduled post that does not exist, or belongs to somebody else.
 *
 * Both cases are one exception on purpose: the API answers 404 either way, so
 * the route cannot be used to probe another account's schedule.
 */
final class ScheduledPostNotFoundException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('The scheduled post ' . $id . ' does not exist.');
	}
}
