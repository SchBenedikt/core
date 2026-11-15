<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use function array_slice;
use function usort;

/**
 * Test/demo adapter with the same owner boundaries as the PDO store.
 */
final class InMemoryRecommendationRepository implements RecommendationRepository
{
	/** @var array<string, RecommendationPreference> */
	private array $preferences = [];

	/** @var array<string, array<string, RecommendationFeedback>> */
	private array $feedback = [];

	/** @var array<string, list<RecommendationSignal>> */
	private array $signals = [];

	public function preference(string $userId): ?RecommendationPreference
	{
		return $this->preferences[$userId] ?? null;
	}

	public function savePreference(RecommendationPreference $preference): void
	{
		$this->preferences[$preference->userId] = $preference;
	}

	public function deletePreference(string $userId): void
	{
		unset($this->preferences[$userId]);
	}

	public function feedback(string $userId, string $candidateId): ?RecommendationFeedback
	{
		return $this->feedback[$userId][$candidateId] ?? null;
	}

	public function feedbackFor(string $userId): array
	{
		$rows = $this->feedback[$userId] ?? [];

		return array_values($rows);
	}

	public function saveFeedback(RecommendationFeedback $feedback): void
	{
		$this->feedback[$feedback->userId][$feedback->candidateId] = $feedback;
	}

	public function deleteFeedback(string $userId, string $candidateId): bool
	{
		$exists = isset($this->feedback[$userId][$candidateId]);
		unset($this->feedback[$userId][$candidateId]);

		return $exists;
	}

	public function deleteFeedbackFor(string $userId): void
	{
		unset($this->feedback[$userId]);
	}

	public function appendSignal(RecommendationSignal $signal): void
	{
		$this->signals[$signal->userId][] = $signal;
	}

	public function signalsFor(string $userId, int $limit = 1000): array
	{
		$rows = $this->signals[$userId] ?? [];
		usort($rows, static fn (RecommendationSignal $a, RecommendationSignal $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

		return array_slice($rows, 0, $limit);
	}

	public function signalCount(string $userId): int
	{
		return count($this->signals[$userId] ?? []);
	}

	public function deleteSignalsFor(string $userId): void
	{
		unset($this->signals[$userId]);
	}

	public function deleteForUser(string $userId): void
	{
		$this->deletePreference($userId);
		$this->deleteFeedbackFor($userId);
		$this->deleteSignalsFor($userId);
	}
}
