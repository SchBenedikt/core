<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Conversation;

final class InMemoryConversationMarkerRepository implements ConversationMarkerRepository
{
	/** @var array<string, ConversationMarker> */
	private array $markers = [];

	public function find(string $userId, string $conversationId): ?ConversationMarker
	{
		return $this->markers[ConversationMarker::idFor($userId, $conversationId)] ?? null;
	}

	public function save(ConversationMarker $marker): void
	{
		$this->markers[$marker->id] = $marker;
	}
}
