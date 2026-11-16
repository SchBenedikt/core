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
	) {
	}

	public function complete(): bool
	{
		return $this->blobFailures === [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'deleted' => $this->counts,
			'blobs_deleted' => $this->blobsDeleted,
			'blob_failures' => $this->blobFailures,
		];
	}
}
