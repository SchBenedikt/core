<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;

/**
 * One consented signal. It is owner-scoped and never shared with another
 * reader's ranking or export.
 */
final readonly class RecommendationSignal
{
	public function __construct(
		public string $id,
		public string $userId,
		public RecommendationSignalKind $kind,
		public string $candidateId,
		public DateTimeImmutable $createdAt,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('A recommendation signal needs a bounded id.');
		}

		if ($userId === '') {
			throw new InvalidArgumentException('A recommendation signal needs a user.');
		}

		if ($candidateId === '' || mb_strlen($candidateId) > RecommendationCandidate::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A recommendation signal needs a candidate.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'kind' => $this->kind->value,
			'candidate_id' => $this->candidateId,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
