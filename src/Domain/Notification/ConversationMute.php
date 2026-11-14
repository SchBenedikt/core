<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function hash;
use function mb_strlen;
use function substr;

/**
 * A private, thread-root-keyed notification mute.
 *
 * It suppresses alerts for every reply under the root, local or remote, while
 * the posts themselves stay visible. The root is a post id or a remote object
 * id; the notification service resolves a reply to its root before asking.
 */
final readonly class ConversationMute
{
	public const MAX_ID_LENGTH = 512;

	public function __construct(
		public string $id,
		public string $userId,
		public string $conversationId,
		public DateTimeImmutable $createdAt,
	) {
		if ($id === '' || $userId === '') {
			throw new InvalidArgumentException('A conversation mute needs an id and a user.');
		}

		if ($conversationId === '' || mb_strlen($conversationId) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('The conversation root is missing or too long.');
		}
	}

	public static function idFor(string $userId, string $conversationId): string
	{
		return 'conversation-mute-' . substr(hash('sha256', $userId . "\0" . $conversationId), 0, 32);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'conversation_id' => $this->conversationId,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
