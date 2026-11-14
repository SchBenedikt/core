<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Scheduling;

use AlohaSocial\Core\Jobs\TerminalJobException;
use RuntimeException;
use Throwable;

/**
 * A scheduled publication that retrying cannot fix.
 *
 * The row is already marked `failed` when this is thrown; the job handler
 * turns it into a dead letter so an operator sees the row and the reason
 * instead of a queue that retries a post whose author deleted its media.
 */
final class ScheduledPublicationException extends RuntimeException implements TerminalJobException
{
	public function __construct(
		string $message,
		private readonly string $failureCode,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function errorCode(): string
	{
		return $this->failureCode;
	}
}
