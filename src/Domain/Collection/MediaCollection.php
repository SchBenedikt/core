<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An owner-scoped, ordered collection of that owner's image/video posts.
 *
 * The collection is metadata only: deleting it never deletes a member post,
 * and membership is a reference. Direct visibility makes no sense for a
 * collection because it has no recipient list, so it is refused here.
 */
final readonly class MediaCollection
{
	public const MAX_TITLE_LENGTH = 100;

	public const MAX_DESCRIPTION_LENGTH = 1000;

	public const MAX_ITEMS = 200;

	public function __construct(
		public string $id,
		public string $ownerId,
		public string $title,
		public string $description,
		public Visibility $audience,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $updatedAt,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('A collection needs a bounded id.');
		}

		if ($ownerId === '') {
			throw new InvalidArgumentException('A collection needs an owner.');
		}

		if (trim($title) === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidArgumentException('A collection needs a bounded title.');
		}

		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new InvalidArgumentException('A collection description is too long.');
		}

		if ($audience === Visibility::Direct) {
			throw new InvalidArgumentException('A collection cannot be direct.');
		}

		if ($updatedAt < $createdAt) {
			throw new InvalidArgumentException('A collection cannot be updated before it was created.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'owner_id' => $this->ownerId,
			'title' => $this->title,
			'description' => $this->description,
			'audience' => $this->audience->value,
			'created_at' => Time::format($this->createdAt),
			'updated_at' => Time::format($this->updatedAt),
		];
	}

	public function withDetails(string $title, string $description, Visibility $audience, DateTimeImmutable $updatedAt): self
	{
		return new self($this->id, $this->ownerId, $title, $description, $audience, $this->createdAt, $updatedAt);
	}
}
