<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A remote actor that one local account follows.
 *
 * This is the outgoing edge of the follow graph. The actor id is the
 * identity, an absolute ActivityPub actor URL. There is no inbox here: the
 * collection only answers who the account follows, while delivery uses the
 * incoming follower graph.
 */
final readonly class FollowedActor
{
	public function __construct(
		public string $id,
		public string $localUserId,
		public string $actorId,
		public DateTimeImmutable $followedAt,
	) {
		if ($id === '') {
			throw new InvalidArgumentException('A followed actor needs an id.');
		}

		if ($localUserId === '') {
			throw new InvalidArgumentException('A followed actor needs a local user.');
		}

		if ($actorId === '') {
			throw new InvalidArgumentException('A followed actor needs an actor.');
		}
	}
}
