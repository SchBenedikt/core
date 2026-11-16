<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Account;

/**
 * What one account erasure actually removed.
 *
 * A blob failure is reported, not hidden: the database rows are already gone
 * at that point, and the caller must be able to tell the difference between a
 * complete erasure and an orphaned object that needs a storage sweep.
 */
final readonly class AccountErasureReport
{
	/**
	 * @param array<string, int> $counts
	 * @param list<string> $blobFailures
	 */
	public function __construct(
		public array $counts,
		public int $blobsDeleted,
		public array $blobFailures,
		public string $state = 'erased',
		public ?string $requestedAt = null,
		public ?string $completedAt = null,
	) {
	}

	public function complete(): bool
	{
		return $this->state === 'erased' && $this->blobFailures === [];
	}

	public function inProgress(): bool
	{
		return $this->state === 'frozen' || $this->state === 'erasing';
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
		];
	}
}
