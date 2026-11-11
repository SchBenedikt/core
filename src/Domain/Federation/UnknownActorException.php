<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use RuntimeException;

/**
 * A key id that does not belong to a resolvable actor.
 *
 * The message stays vague on purpose; whether the host failed to fetch, got a
 * 404 or saw a malformed document must not reach the peer.
 */
final class UnknownActorException extends RuntimeException
{
	public function __construct(
		public readonly string $keyId,
		string $message = 'The signing actor could not be resolved.',
	) {
		parent::__construct($message);
	}
}
