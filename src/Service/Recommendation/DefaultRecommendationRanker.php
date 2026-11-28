<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use AlohaSocial\Core\Domain\Recommendation\RecommendationAlgorithm;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedback;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedbackKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationItem;
use AlohaSocial\Core\Domain\Recommendation\RecommendationPreference;
use AlohaSocial\Core\Domain\Recommendation\RecommendationRanker;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignalKind;
use DateTimeImmutable;

use function array_unique;
use function array_values;
use function in_array;
use function intdiv;
use function max;
use function min;
use function usort;

/**
 * A small deterministic ranker: explicit interests first, then freshness,
 * stored engagement and the reader's own consented signals/feedback.
 *
 * There is no machine-learning service, no per-reader collaborative model and
 * no hidden cross-user interest. Every item carries its reasons and the
 * algorithm version, and equal inputs always produce the same order.
 */
final class DefaultRecommendationRanker implements RecommendationRanker
{
	public function rank(
		string $viewerId,
		array $candidates,
		RecommendationPreference $preference,
		array $signals,
		array $feedback,
		DateTimeImmutable $now,
	): array {
		$signalCounts = [];
		foreach ($signals as $signal) {
			$signalCounts[$signal->candidateId][$signal->kind->value] = $signal->kind;
		}

		$items = [];
		foreach ($candidates as $candidate) {
			$score = 10;
			$reasons = [];

			foreach ($candidate->hashtags() as $tag) {
				if (in_array($tag, $preference->interests, true)) {
					$score += 45;
					$reasons[] = 'interest:' . $tag;
				}
			}

			$ageHours = intdiv(max(0, $now->getTimestamp() - $candidate->publishedAt->getTimestamp()), 3600);
			$freshness = max(0, 72 - min(72, $ageHours));
			$score += $freshness;
			if ($freshness > 0) {
				$reasons[] = 'fresh';
			}

			if ($candidate->engagement > 0) {
				$score += min(30, $candidate->engagement);
				$reasons[] = 'engagement';
			}

			foreach ($signalCounts[$candidate->id] ?? [] as $kind) {
				$score += match ($kind) {
					RecommendationSignalKind::Like,
					RecommendationSignalKind::Repost,
					RecommendationSignalKind::Bookmark => 35,
					RecommendationSignalKind::Reply => 30,
					RecommendationSignalKind::Click => 10,
					RecommendationSignalKind::Impression => 0,
				};
				$reasons[] = 'signal:' . $kind->value;
			}

			$verdict = $feedback[$candidate->id] ?? null;
			if ($verdict instanceof RecommendationFeedback) {
				if ($verdict->kind === RecommendationFeedbackKind::More) {
					$score += 70;
					$reasons[] = 'feedback:more';
				} elseif ($verdict->kind === RecommendationFeedbackKind::Less) {
					$score -= 90;
					$reasons[] = 'feedback:less';
				} elseif ($verdict->kind === RecommendationFeedbackKind::Hide) {
					$score -= 1000;
					$reasons[] = 'feedback:hide';
				}
			}

			if ($reasons === []) {
				$reasons[] = 'recency';
			}

			$items[] = new RecommendationItem(
				$candidate,
				$score,
				array_values(array_unique($reasons)),
				RecommendationAlgorithm::VERSION,
			);
		}

		usort($items, static fn (RecommendationItem $a, RecommendationItem $b): int => [$b->score, $b->candidate->publishedAt, $a->candidate->id]
			<=> [$a->score, $a->candidate->publishedAt, $b->candidate->id]);

		return $items;
	}
}
