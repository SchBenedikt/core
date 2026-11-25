<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Conversation;

/** Private per-account read and dismissal state, keyed by thread root. */
interface ConversationMarkerRepository
{
	public function find(string $userId, string $conversationId): ?ConversationMarker;

	public function save(ConversationMarker $marker): void;
}
