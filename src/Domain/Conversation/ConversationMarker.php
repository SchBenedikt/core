<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Conversation;

use DateTimeImmutable;
use InvalidArgumentException;

/** Per-account read and dismissal watermarks for one direct-message thread. */
final readonly class ConversationMarker
{
	public function __construct(
		public string $id,
		public string $userId,
		public string $conversationId,
		public ?string $readThroughPostId = null,
		public ?DateTimeImmutable $readThroughAt = null,
		public ?string $dismissedThroughPostId = null,
		public ?DateTimeImmutable $dismissedThroughAt = null,
	) {
		if ($id === '' || $userId === '' || $conversationId === '' || strlen($conversationId) > 512) {
			throw new InvalidArgumentException('A conversation marker needs a user and thread root.');
		}
		if (($readThroughPostId === null) !== ($readThroughAt === null)
			|| ($dismissedThroughPostId === null) !== ($dismissedThroughAt === null)
		) {
			throw new InvalidArgumentException('Conversation marker watermarks require both a post id and timestamp.');
		}
	}

	public static function idFor(string $userId, string $conversationId): string
	{
		return 'conversation-' . substr(hash('sha256', $userId . "\0" . $conversationId), 0, 32);
	}
}
