<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationEligibility;
use AlohaSocial\Core\Domain\Recommendation\RecommendationPreference;
use AlohaSocial\Core\Platform\Clock\Clock;

use function array_fill_keys;
use function array_values;
use function in_array;

/**
 * The fail-closed per-reader filter for ranked candidates.
 *
 * The candidate source already limits itself to public rows; this class
 * re-checks visibility, excludes sensitive content and the reader's own posts,
 * applies the reader's blocks/mutes, the instance moderation policy and the
 * optional language preference. Unknown language rows are excluded when the
 * reader asked for specific languages.
 */
final class StoredRecommendationEligibility implements RecommendationEligibility
{
	public function __construct(
		private readonly BlockRepository $blocks,
		private readonly MuteRepository $mutes,
		private readonly ModerationPolicy $moderation,
		private readonly Clock $clock,
	) {
	}

	public function filter(
		string $viewerId,
		array $candidates,
		RecommendationPreference $preference,
	): array {
		$blocked = array_fill_keys($this->blocks->actorIds($viewerId), true);
		$muted = array_fill_keys($this->mutes->actorIds($viewerId, $this->clock->now()), true);
		$allowed = [];

		foreach ($candidates as $candidate) {
			if ($candidate->visibility !== Visibility::Public
				|| $candidate->authorId === $viewerId
				|| $candidate->contentWarning !== null
				|| isset($blocked[$candidate->authorId])
				|| isset($muted[$candidate->authorId])
				|| ($candidate->objectAuthorId !== null && isset($blocked[$candidate->objectAuthorId]))
				|| ($candidate->objectAuthorId !== null && isset($muted[$candidate->objectAuthorId]))
				|| !$this->moderation->allowsActor($candidate->authorId)
				|| ($candidate->objectAuthorId !== null && !$this->moderation->allowsActor($candidate->objectAuthorId))
			) {
				continue;
			}

			if ($preference->languages !== [] && !$candidate->sharesLanguage($preference->languages)) {
				continue;
			}

			$allowed[] = $candidate;
		}

		return $allowed;
	}
}
