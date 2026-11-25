<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Events;

/** An event before the host broker assigns its stable replay cursor. */
final readonly class StreamEventDraft
{
	/**
	 * @param list<string> $recipientIds Local accounts eligible for home delivery.
	 * @param array<string, mixed> $payload
	 */
	public function __construct(
		public string $name,
		public StreamAudience $audience,
		public string $actorId,
		public array $recipientIds,
		public array $payload,
		public string $occurredAt,
	) {
		if ($name === '' || $actorId === '' || $occurredAt === '') {
			throw new \InvalidArgumentException('Stream event drafts require a name, actor, and timestamp.');
		}
		if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1) {
			throw new \InvalidArgumentException('Stream event names must be stable lowercase identifiers.');
		}
		if ($recipientIds === []) {
			throw new \InvalidArgumentException('Stream event drafts require at least one eligible local account.');
		}
		if (count($recipientIds) !== count(array_unique($recipientIds)) || in_array('', $recipientIds, true)) {
			throw new \InvalidArgumentException('Stream event recipients must be unique non-empty account ids.');
		}
	}
}
