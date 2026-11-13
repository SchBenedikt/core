<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use InvalidArgumentException;

/**
 * A cleanup job payload that cannot be executed.
 *
 * The code is stable for logs and operator tooling. It is deliberately not an
 * HTTP error: cleanup is a worker operation, not a public route.
 */
final class InvalidCleanupOptionsException extends InvalidArgumentException
{
	public function __construct(
		public readonly string $errorCode,
		string $message,
	) {
		parent::__construct($message);
	}
}
