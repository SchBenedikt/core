<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_map;

/**
 * The results of one cleanup run, one entry per requested kind.
 */
final readonly class CleanupReport
{
	/**
	 * @param list<CleanupResult> $results
	 */
	public function __construct(
		public DateTimeImmutable $runAt,
		public bool $dryRun,
		public array $results,
	) {
	}

	public function matched(): int
	{
		$total = 0;
		foreach ($this->results as $result) {
			$total += $result->matched;
		}

		return $total;
	}

	public function deleted(): int
	{
		$total = 0;
		foreach ($this->results as $result) {
			$total += $result->deleted;
		}

		return $total;
	}

	public function remaining(): int
	{
		$total = 0;
		foreach ($this->results as $result) {
			$total += $result->remaining();
		}

		return $total;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'run_at' => Time::format($this->runAt),
			'dry_run' => $this->dryRun,
			'matched' => $this->matched(),
			'deleted' => $this->deleted(),
			'remaining' => $this->remaining(),
			'results' => array_map(
				static fn (CleanupResult $result): array => $result->toArray(),
				$this->results,
			),
		];
	}
}
