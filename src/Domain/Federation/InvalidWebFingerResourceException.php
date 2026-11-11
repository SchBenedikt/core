<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use RuntimeException;

/**
 * A WebFinger `resource` that is not a usable `acct:` URI.
 *
 * The reason is for logs and tests; the handler never echoes it, so a probe
 * cannot learn which part of its input was rejected.
 */
final class InvalidWebFingerResourceException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct('Invalid WebFinger resource: ' . $reason . '.');
	}
}
