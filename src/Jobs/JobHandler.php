<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

/**
 * Runs one job type.
 *
 * A handler that throws causes the queue to retry the job; a handler that
 * returns has completed it.
 */
interface JobHandler
{
	public function handle(Job $job): void;
}
