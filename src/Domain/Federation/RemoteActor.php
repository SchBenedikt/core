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
	public const MAX_URL_LENGTH = 512;

	public function __construct(
		public string $actorId,
		public string $keyId,
		public string $inboxUrl,
		public string $publicKeyPem,
		public ?string $sharedInboxUrl = null,
		/** @var list<string> the actor's signed-identity aliases */
		public array $alsoKnownAs = [],
		/** The actor this account moved to, when the document advertises one. */
		public ?string $movedTo = null,
		/** The actor's followers collection, which may use a non-standard URL. */
		public ?string $followersUrl = null,
	) {
		if ($actorId === '' || $keyId === '' || $inboxUrl === '' || $publicKeyPem === '') {
			throw new InvalidArgumentException('A remote actor needs an id, a key id, an inbox and a public key.');
		}

		if ($sharedInboxUrl !== null && $sharedInboxUrl === '') {
			throw new InvalidArgumentException('A remote shared inbox must be an absolute URL when present.');
		}

		if ($movedTo !== null && $movedTo === '') {
			throw new InvalidArgumentException('A movedTo actor must be an absolute URL when present.');
		}

		if ($followersUrl !== null && $followersUrl === '') {
			throw new InvalidArgumentException('A followers collection must be an absolute URL when present.');
		}
	}

}
