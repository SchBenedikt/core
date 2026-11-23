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
	public function block(string $localUserId, string $actorId): void;

	public function unblock(string $localUserId, string $actorId): void;

	public function isBlocked(string $localUserId, string $actorId): bool;
}
