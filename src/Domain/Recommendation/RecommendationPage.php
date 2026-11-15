<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_values;
use function is_array;

/**
 * One page of a ranked recommendation snapshot.
 */
final readonly class RecommendationPage
{
	/**
	 * @param list<RecommendationItem> $items
	 */
	public function __construct(
		public array $items,
		public ?string $nextCursor,
		public ?string $snapshotId,
		public ?DateTimeImmutable $expiresAt,
		public bool $enabled,
		public bool $underfilled,
		public string $algorithmVersion,
	) {
		if (!is_array($items) || array_values($items) !== $items) {
			throw new \InvalidArgumentException('Recommendation page items must be a list.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'items' => array_map(static fn (RecommendationItem $item): array => $item->toArray(), $this->items),
			'next_cursor' => $this->nextCursor,
			'snapshot_id' => $this->snapshotId,
			'expires_at' => $this->expiresAt === null ? null : Time::format($this->expiresAt),
			'enabled' => $this->enabled,
			'underfilled' => $this->underfilled,
			'algorithm_version' => $this->algorithmVersion,
		];
	}
}
