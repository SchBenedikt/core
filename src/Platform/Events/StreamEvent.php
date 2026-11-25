<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Events;

/** A durable, ordered event envelope shared by real-time host transports. */
final readonly class StreamEvent
{
	/**
	 * @param array<string, mixed> $payload
	 * @param list<string> $recipientIds Local accounts eligible for home delivery.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public StreamAudience $audience,
		public string $actorId,
		public array $recipientIds,
		public array $payload,
		public string $occurredAt,
	) {
		if ($id === '' || $name === '' || $actorId === '') {
			throw new \InvalidArgumentException('Stream events require an id, name, and actor.');
		}
		if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1) {
			throw new \InvalidArgumentException('Stream event names must be stable lowercase identifiers.');
		}
		if ($recipientIds === []) {
			throw new \InvalidArgumentException('Stream events require at least one eligible local account.');
		}
		if (count($recipientIds) !== count(array_unique($recipientIds)) || in_array('', $recipientIds, true)) {
			throw new \InvalidArgumentException('Stream event recipients must be unique non-empty account ids.');
		}
		if ($occurredAt === '') {
			throw new \InvalidArgumentException('Stream events require a timestamp.');
		}
	}
}
