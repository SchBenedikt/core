<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Events;

/**
 * Host-owned durable event storage for realtime transports.
 *
 * Implementations must preserve a stable cursor order across processes,
 * enforce each subscription's audience/recipient visibility, and bound reads
 * to the requested limit. Core deliberately does not keep a process-global
 * event bus: hosts can use their shared database or another durable broker.
 */
interface EventBroker
{
	/** Persist the event in the caller's current write transaction when possible. */
	public function publish(StreamEventDraft $event): void;

	/** @return list<StreamEvent> Events strictly after $cursor, in cursor order. */
	public function read(StreamSubscription $subscription, ?string $cursor, int $limit): array;
}
