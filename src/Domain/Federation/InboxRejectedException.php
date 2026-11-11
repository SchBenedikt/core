<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use RuntimeException;

/**
 * An inbound request refused before any state changed.
 *
 * `reason` is the stable code the API answers with; it never contains a
 * driver message, a stack trace or the signature that failed.
 */
final class InboxRejectedException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
