<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * Owner-scoped behavioral signals. Writes are only made after consent.
 */
interface RecommendationSignalRepository
{
	public function appendSignal(RecommendationSignal $signal): void;

	/**
	 * @return list<RecommendationSignal> newest first
	 */
	public function signalsFor(string $userId, int $limit = 1000): array;

	/**
	 * The newest stored signal for one owner/kind/candidate pair recorded at
	 * or after `$since`, or null. Used to collapse noisy repeated events.
	 */
	public function recentSignal(
		string $userId,
		RecommendationSignalKind $kind,
		string $candidateId,
		\DateTimeImmutable $since,
	): ?RecommendationSignal;

	public function signalCount(string $userId): int;

	public function deleteSignalsFor(string $userId): void;
}
