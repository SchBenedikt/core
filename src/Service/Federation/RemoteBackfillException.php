<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A backfill that could not run or could not fetch a valid page.
 *
 * Malformed individual activities do not abort a backfill; they are counted
 * as skipped. This exception is for a refused actor, an unusable outbox URL,
 * an out-of-scope pagination link or a page that could not be fetched.
 */
final class RemoteBackfillException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
