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
		$existing = $this->markers[$marker->id] ?? null;
		if ($existing === null) {
			$this->markers[$marker->id] = $marker;
			return;
		}

		[$readId, $readAt] = self::latest(
			$existing->readThroughPostId,
			$existing->readThroughAt,
			$marker->readThroughPostId,
			$marker->readThroughAt,
		);
		[$dismissedId, $dismissedAt] = self::latest(
			$existing->dismissedThroughPostId,
			$existing->dismissedThroughAt,
			$marker->dismissedThroughPostId,
			$marker->dismissedThroughAt,
		);
		$this->markers[$marker->id] = new ConversationMarker(
			id: $marker->id,
			userId: $marker->userId,
			conversationId: $marker->conversationId,
			readThroughPostId: $readId,
			readThroughAt: $readAt,
			dismissedThroughPostId: $dismissedId,
			dismissedThroughAt: $dismissedAt,
		);
	}

	/** @return array{?string, ?\DateTimeImmutable} */
	private static function latest(?string $leftId, ?\DateTimeImmutable $leftAt, ?string $rightId, ?\DateTimeImmutable $rightAt): array
	{
		if ($leftAt === null || $leftId === null) {
			return [$rightId, $rightAt];
		}
		if ($rightAt === null || $rightId === null) {
			return [$leftId, $leftAt];
		}

		return $rightAt > $leftAt || ($rightAt == $leftAt && strcmp($rightId, $leftId) > 0)
			? [$rightId, $rightAt]
			: [$leftId, $leftAt];
	}
}
