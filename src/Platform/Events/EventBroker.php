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
 * honor the channel semantics in each subscription (including restricting
 * `public:local` to locally owned actors), enforce audience/recipient
 * visibility, and bound reads to the requested limit. A `list` subscription
 * is private to its owner: validate that the list belongs to `userId`, read
 * only statuses by current members, and recheck list membership and status
 * visibility before writing a frame. Direct statuses never enter a list
 * stream. Local actor and list ownership lookup are host-owned. Core
 * deliberately does not keep a process-global event bus: hosts can use their
 * shared database or another durable broker.
 */
interface EventBroker
{
	/** Persist the event in the caller's current write transaction when possible. */
	public function publish(StreamEventDraft $event): void;

	/** @return list<StreamEvent> Events strictly after $cursor, in cursor order. */
	public function read(StreamSubscription $subscription, ?string $cursor, int $limit): array;
}
