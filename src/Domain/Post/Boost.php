<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;
use RuntimeException;

/**
 * One local account sharing one post.
 *
 * The id is the id of the Announce activity the instance publishes. It is
 * therefore stable across a retry and can be used as the Mastodon reblog
 * status id.
 */
final readonly class Boost
{
	public function __construct(
		public string $id,
		public string $userId,
		public string $postId,
		public DateTimeImmutable $createdAt,
	) {
		if ($id === '' || $userId === '' || $postId === '') {
			throw new RuntimeException('A boost needs an id, a user and a post.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'post_id' => $this->postId,
			'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
		];
	}
}
