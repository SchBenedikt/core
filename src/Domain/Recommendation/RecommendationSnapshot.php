<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function is_array;

/**
 * A bounded ranked snapshot. A cursor points into one snapshot, so a reader
 * never receives a different ranking halfway through a page sequence.
 */
final readonly class RecommendationSnapshot
{
	/**
	 * @param list<RecommendationItem> $items
	 */
	public function __construct(
		public string $id,
		public string $userId,
		public array $items,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $expiresAt,
		public string $algorithmVersion,
	) {
		if ($id === '' || $userId === '') {
			throw new InvalidArgumentException('A recommendation snapshot needs an owner.');
		}

		if (!is_array($items) || array_values($items) !== $items) {
			throw new InvalidArgumentException('Recommendation snapshot items must be a list.');
		}

		if ($expiresAt < $createdAt) {
			throw new InvalidArgumentException('A recommendation snapshot cannot expire before it was created.');
		}

		if ($algorithmVersion === '') {
			throw new InvalidArgumentException('A recommendation snapshot needs an algorithm version.');
		}
	}

	public function expiredAt(DateTimeImmutable $now): bool
	{
		return $now >= $this->expiresAt;
	}
}
