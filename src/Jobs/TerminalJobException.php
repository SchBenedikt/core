<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use Throwable;

/**
 * A job failure that retrying cannot fix.
 *
 * The worker dead-letters these immediately, without spending the remaining
 * attempts on a payload or a handler that can never succeed. The code is
 * stable for operators and tests; it is not an HTTP error code.
 */
interface TerminalJobException extends Throwable
{
	public function errorCode(): string;
}
