<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * What one cleanup kind found and removed.
 *
 * `matched` is the total number of eligible rows at the cutoff, while
 * `deleted` is bounded by the run limit. `remaining` therefore tells an
 * operator whether another run is needed.
 */
final readonly class CleanupResult
{
	public function __construct(
		public string $kind,
		public DateTimeImmutable $cutoff,
		public int $matched,
		public int $deleted,
		public bool $dryRun,
		public ?string $skippedReason = null,
	) {
	}

	public function remaining(): int
	{
		return max(0, $this->matched - $this->deleted);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$result = [
			'kind' => $this->kind,
			'cutoff' => Time::format($this->cutoff),
			'matched' => $this->matched,
			'deleted' => $this->deleted,
			'remaining' => $this->remaining(),
			'dry_run' => $this->dryRun,
		];

		if ($this->skippedReason !== null) {
			$result['skipped_reason'] = $this->skippedReason;
		}

		return $result;
	}
}
