<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;

/**
 * A short-lived, followers-only story.
 *
 * A story is deliberately not a {@see \AlohaSocial\Core\Domain\Post\Post}: it
 * has no public URL, is never enumerated in a post timeline, and is always
 * addressed to the owner's followers. The stable object id is what a peer
 * uses for an incoming Delete, so it is kept even after the local row expires.
 */
final readonly class Story
{
	public const MAX_ID_LENGTH = 512;

	public const MAX_OBJECT_LENGTH = 512;

	public const MAX_CAPTION_LENGTH = 2000;

	public const MAX_MEDIA_URL_LENGTH = 2048;

	public function __construct(
		public string $id,
		public string $ownerId,
		public bool $local,
		public string $objectId,
		public string $caption,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $expiresAt,
		public ?string $attachmentId = null,
		public ?string $remoteMediaUrl = null,
		public ?DateTimeImmutable $deletedAt = null,
	) {
		if ($id === '' || mb_strlen($id) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A story needs a bounded id.');
		}

		if ($ownerId === '' || mb_strlen($ownerId) > 512) {
			throw new InvalidArgumentException('A story needs an owner.');
		}

		if ($objectId === '' || mb_strlen($objectId) > self::MAX_OBJECT_LENGTH) {
			throw new InvalidArgumentException('A story needs a stable object id.');
		}

		if (mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
			throw new InvalidArgumentException('A story caption is too long.');
		}

		if ($expiresAt <= $createdAt) {
			throw new InvalidArgumentException('A story must expire after it was published.');
		}

		if (($attachmentId === null) === ($remoteMediaUrl === null)) {
			throw new InvalidArgumentException('A story needs exactly one media reference.');
		}

		if ($attachmentId !== null && ($attachmentId === '' || mb_strlen($attachmentId) > 64)) {
			throw new InvalidArgumentException('A story attachment id is invalid.');
		}

		if ($remoteMediaUrl !== null && ($remoteMediaUrl === '' || mb_strlen($remoteMediaUrl) > self::MAX_MEDIA_URL_LENGTH)) {
			throw new InvalidArgumentException('A story media URL is invalid.');
		}

		if ($deletedAt !== null && $deletedAt < $createdAt) {
			throw new InvalidArgumentException('A story cannot be deleted before it was published.');
		}
	}

	public function isDeleted(): bool
	{
		return $this->deletedAt !== null;
	}

	public function isExpiredAt(DateTimeImmutable $now): bool
	{
		return $now >= $this->expiresAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'owner_id' => $this->ownerId,
			'local' => $this->local,
			'object_id' => $this->objectId,
			'caption' => $this->caption,
			'published_at' => Time::format($this->createdAt),
			'expires_at' => Time::format($this->expiresAt),
			'deleted_at' => $this->deletedAt === null ? null : Time::format($this->deletedAt),
			'attachment_id' => $this->attachmentId,
			'remote_media_url' => $this->remoteMediaUrl,
		];
	}

	public function withDeletedAt(DateTimeImmutable $at): self
	{
		return new self(
			$this->id,
			$this->ownerId,
			$this->local,
			$this->objectId,
			$this->caption,
			$this->createdAt,
			$this->expiresAt,
			$this->attachmentId,
			$this->remoteMediaUrl,
			$at,
		);
	}
}
