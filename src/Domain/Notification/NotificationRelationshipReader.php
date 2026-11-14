<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * The two follow directions one notification policy needs.
 *
 * It is deliberately a port rather than a dependency on the follow services:
 * those services emit notifications, and a policy service that called them
 * would form a cycle. A host without a follow graph uses the null reader and
 * every sender is classified as new or stranger.
 */
interface NotificationRelationshipReader
{
	public function follows(string $recipientId, string $actorId): bool;

	public function isFollowedBy(string $recipientId, string $actorId): bool;
}
