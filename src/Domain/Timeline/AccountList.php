<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * One private named list of local accounts.
 *
 * Lists are owned by one local account. Membership is stored separately, so a
 * list and its members can be updated without rewriting the owning row. The
 * title is the only client-visible identity; the id is opaque.
 */
final readonly class AccountList
{
	public const MAX_ID_LENGTH = 64;

	public const MAX_USER_LENGTH = 255;

	public const MAX_TITLE_LENGTH = 255;

	public const MAX_ACCOUNT_LENGTH = 255;

	public string $title;

	public function __construct(
		public string $id,
		public string $userId,
		string $title,
		public ListRepliesPolicy $repliesPolicy,
		public bool $exclusive,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $updatedAt,
	) {
		if ($id === '' || mb_strlen($id) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A list needs an id of at most 64 characters.');
		}

		if ($userId === '' || mb_strlen($userId) > self::MAX_USER_LENGTH) {
			throw new InvalidArgumentException('A list needs a user id of at most 255 characters.');
		}

		$title = trim($title);
		if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidArgumentException('A list needs a title of at most 255 characters.');
		}

		$this->title = $title;
	}

	/**
	 * The shape the Mastodon compatibility API returns.
	 *
	 * `user_id` is deliberately absent: a list is always scoped to the
	 * authenticated caller, and echoing the owner would invite a client to
	 * treat it as a public field.
	 *
	 * @return array{id: string, title: string, replies_policy: string, exclusive: bool}
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'replies_policy' => $this->repliesPolicy->value,
			'exclusive' => $this->exclusive,
		];
	}
}
