<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * What one sync page changed.
 *
 * Counts are deliberately small and stable enough for a worker log or a host
 * status page. `conflicted` events were refused because the same sequence
 * described a different CID; they are not applied.
 */
final readonly class AtprotoSyncResult
{
	public function __construct(
		public string $source,
		public int $applied,
		public int $skipped,
		public int $conflicted,
		public int $deleted,
		public int $pages,
		public bool $reachedEnd,
		public AtprotoSyncCheckpoint $checkpoint,
		public AtprotoSourceStatus $status,
	) {
	}

	public function reconcileRequired(): bool
	{
		return $this->checkpoint->reconcileRequired;
	}
}
