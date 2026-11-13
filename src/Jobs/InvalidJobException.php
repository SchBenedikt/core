<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use InvalidArgumentException;

/**
 * A job that cannot be queued at all.
 *
 * The message is for humans; `errorCode` is stable for tooling.
 */
final class InvalidJobException extends InvalidArgumentException
{
	public function __construct(
		public readonly string $errorCode,
		string $message,
	) {
		parent::__construct($message);
	}
}
