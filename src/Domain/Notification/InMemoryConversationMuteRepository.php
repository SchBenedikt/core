<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

final class InMemoryConversationMuteRepository implements ConversationMuteRepository
{
	/**
	 * @var array<string, ConversationMute>
	 */
	private array $mutes = [];

	public function save(ConversationMute $mute): void
	{
		$this->mutes[$mute->id] = $mute;
	}

	public function find(string $userId, string $conversationId): ?ConversationMute
	{
		return $this->mutes[ConversationMute::idFor($userId, $conversationId)] ?? null;
	}

	public function remove(string $userId, string $conversationId): bool
	{
		$id = ConversationMute::idFor($userId, $conversationId);
		if (!isset($this->mutes[$id])) {
			return false;
		}

		unset($this->mutes[$id]);

		return true;
	}
}
