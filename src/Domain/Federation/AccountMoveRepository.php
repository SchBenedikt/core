<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Durable account-move records.
 *
 * The event id is the primary key, and the processed lists live on the row so
 * a worker that dies mid-migration can resume from the database rather than a
 * process-local cursor. Outbound records are looked up by origin to render the
 * actor's `movedTo`; inbound records are looked up by event id.
 */
interface AccountMoveRepository
{
	public function insert(AccountMove $move): void;

	public function find(string $eventId): ?AccountMove;

	public function findOutboundByOrigin(string $originActorId): ?AccountMove;

	public function save(AccountMove $move): void;
}
