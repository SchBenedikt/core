<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

use function array_slice;
use function usort;

/**
 * A bounded in-memory candidate source.
 */
final class InMemoryRecommendationCandidateSource implements RecommendationCandidateSource
{
	/** @var list<RecommendationCandidate> */
	private array $candidates = [];

	/**
	 * @param list<RecommendationCandidate> $candidates
	 */
	public function __construct(array $candidates = [])
	{
		foreach ($candidates as $candidate) {
			$this->add($candidate);
		}
	}

	public function add(RecommendationCandidate $candidate): void
	{
		$this->candidates[] = $candidate;
	}

	public function candidates(string $viewerId, int $limit, DateTimeImmutable $now): array
	{
		$rows = $this->candidates;
		usort($rows, static fn (RecommendationCandidate $a, RecommendationCandidate $b): int => [$b->publishedAt, $b->id] <=> [$a->publishedAt, $a->id]);

		return array_slice($rows, 0, max(1, $limit));
	}
}
