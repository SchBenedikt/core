<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use InvalidArgumentException;

use function array_values;
use function is_array;
use function is_string;

/**
 * A ranked candidate with an explicit score and explanation.
 *
 * The reasons are a bounded, stable vocabulary for the host; the score is an
 * implementation detail rather than a promise of a global relevance model.
 */
final readonly class RecommendationItem
{
	/**
	 * @param list<string> $reasons
	 */
	public function __construct(
		public RecommendationCandidate $candidate,
		public int $score,
		public array $reasons,
		public string $algorithmVersion,
	) {
		if ($algorithmVersion === '') {
			throw new InvalidArgumentException('A recommendation item needs an algorithm version.');
		}

		if (!is_array($reasons) || array_values($reasons) !== $reasons) {
			throw new InvalidArgumentException('Recommendation reasons must be a list.');
		}

		foreach ($reasons as $reason) {
			if (!is_string($reason) || $reason === '') {
				throw new InvalidArgumentException('A recommendation reason is invalid.');
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'candidate' => $this->candidate->toArray(),
			'score' => $this->score,
			'reasons' => $this->reasons,
			'algorithm_version' => $this->algorithmVersion,
		];
	}

	public function withScore(int $score, array $reasons): self
	{
		return new self($this->candidate, $score, $reasons, $this->algorithmVersion);
	}

}
