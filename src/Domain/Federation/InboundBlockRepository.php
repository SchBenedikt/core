<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/** Stores remote actors that have blocked a local account. */
interface InboundBlockRepository
{
	public function block(string $localUserId, string $actorId, string $activityId): void;

	/** Removes only the block activity identified by its actor-owned URI. */
	public function unblockByActivityId(string $actorId, string $activityId, ?string $localUserId = null): bool;

	public function isBlocked(string $localUserId, string $actorId): bool;
}
