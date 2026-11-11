<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use RuntimeException;

/**
 * A job was queued with a type this worker has no handler for.
 */
final class UnknownJobTypeException extends RuntimeException
{
	public function __construct(
		public readonly string $jobType,
	) {
		parent::__construct('No handler is registered for job type "' . $jobType . '".');
	}
}
