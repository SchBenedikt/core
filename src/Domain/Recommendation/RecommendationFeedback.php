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
 * One reader's explicit verdict on one candidate.
 */
final readonly class RecommendationFeedback
{
	public function __construct(
		public string $userId,
		public string $candidateId,
		public RecommendationFeedbackKind $kind,
		public DateTimeImmutable $createdAt,
	) {
		if ($userId === '') {
			throw new InvalidArgumentException('Recommendation feedback needs a user.');
		}

		if ($candidateId === '' || mb_strlen($candidateId) > RecommendationCandidate::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('Recommendation feedback needs a candidate.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'candidate_id' => $this->candidateId,
			'action' => $this->kind->value,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
