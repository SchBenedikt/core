<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use InvalidArgumentException;

/**
 * The signing half of a remote actor, as needed to verify an inbox request.
 *
 * It carries the inbox as well because a valid Follow has to be answered: the
 * request that proves the actor owns the key is also the moment we learn where
 * to deliver the Accept.
 */
final readonly class RemoteActor
{
	public function __construct(
		public string $actorId,
		public string $keyId,
		public string $inboxUrl,
		public string $publicKeyPem,
	) {
		if ($actorId === '' || $keyId === '' || $inboxUrl === '' || $publicKeyPem === '') {
			throw new InvalidArgumentException('A remote actor needs an id, a key id, an inbox and a public key.');
		}
	}
}
