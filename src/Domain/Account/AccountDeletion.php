<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * The persisted lifecycle of one account deletion.
 *
 * `active` is the answer for an account that has never requested a deletion;
 * it is not stored. `frozen` blocks every account write before the first row
 * is removed, `erasing` marks the one worker that is allowed to finish the
 * work, and `erased` is the permanent, repeatable result.
 */
final readonly class AccountDeletion
{
	public const ACTIVE = 'active';

	public const FROZEN = 'frozen';

	public const ERASING = 'erasing';

	public const ERASED = 'erased';

	/**
	 * @param array<string, int> $counts
	 * @param list<string> $blobFailures
	 */
	public function __construct(
		public string $userId,
		public string $state,
		public ?string $requestedAt,
		public ?string $completedAt,
		public array $counts = [],
		public int $blobsDeleted = 0,
		public array $blobFailures = [],
		public int $attempts = 0,
	) {
	}

	public static function active(string $userId): self
	{
		return new self($userId, self::ACTIVE, null, null);
	}

	public function erased(): bool
	{
		return $this->state === self::ERASED;
	}

	public function inProgress(): bool
	{
		return $this->state === self::FROZEN || $this->state === self::ERASING;
	}

	/**
	 * The HTTP status a blocked write receives, or null when writes are
	 * allowed. An erased account is gone (410); a frozen one may still be
	 * completing (403).
	 */
	public function blockedStatus(): ?int
	{
		return match ($this->state) {
			self::ACTIVE => null,
			self::ERASED => 410,
			default => 403,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'state' => $this->state,
			'requested_at' => $this->requestedAt,
			'completed_at' => $this->completedAt,
			'deleted' => $this->counts,
			'blobs_deleted' => $this->blobsDeleted,
			'blob_failures' => $this->blobFailures,
			'attempts' => $this->attempts,
		];
	}
}
