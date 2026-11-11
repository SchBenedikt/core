<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use RuntimeException;

/**
 * A handle that does not belong to an account on this instance.
 *
 * The message never contains the handle itself, so it cannot end up echoing
 * a probe back to the caller.
 */
final class UnknownLocalActorException extends RuntimeException
{
	public function __construct(
		public readonly string $handle,
	) {
		parent::__construct('Unknown local actor.');
	}
}
