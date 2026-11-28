<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use RuntimeException;

/**
 * A caller-supplied schedule id was already taken by a row whose payload
 * differs, so the retry cannot be replayed safely.
 */
final class ScheduledPostIdempotencyConflictException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('The schedule id ' . $id . ' is already used for a different payload.');
	}
}
