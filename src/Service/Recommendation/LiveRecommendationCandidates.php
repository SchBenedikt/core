<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateRefresher;
use AlohaSocial\Core\Service\Federation\RemotePostAccess;
use DateTimeImmutable;

use function array_values;

/**
 * Rehydrates recommendation candidates from the canonical stores.
 *
 * Local candidates must still be live, unarchived public posts; remote
 * candidates must still be stored public posts the viewer may read under the
 * shared {@see RemotePostAccess} rule — a remote row that was deleted,
 * narrowed to followers or blocked since the snapshot was written fails
 * closed. Both lookups are bounded batch reads; no peer is contacted while a
 * feed page is served.
 */
final class LiveRecommendationCandidates implements RecommendationCandidateRefresher
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly ?RemotePostRepository $remotePosts = null,
		private readonly ?RemotePostAccess $remoteAccess = null,
	) {
	}

	public function refresh(string $viewerId, array $candidates, DateTimeImmutable $now): array
	{
		$localIds = [];
		$remoteIds = [];
		foreach ($candidates as $candidate) {
			if ($candidate->kind === RecommendationCandidateKind::Local) {
				$localIds[] = $candidate->targetId;
			} else {
				$remoteIds[] = $candidate->targetId;
			}
		}

		$livePosts = $this->posts->findMany($localIds);
		$liveRemote = $this->remotePosts === null
			? []
			: $this->remotePosts->findByActivityIds($remoteIds);

		$refreshed = [];
		foreach ($candidates as $candidate) {
			$live = $candidate->kind === RecommendationCandidateKind::Local
				? $this->localCandidate($candidate, $livePosts[$candidate->targetId] ?? null)
				: $this->remoteCandidate($candidate, $liveRemote[$candidate->targetId] ?? null, $viewerId);

			if ($live !== null) {
				$refreshed[$live->id] = $live;
			}
		}

		return $refreshed;
	}

	private function localCandidate(RecommendationCandidate $candidate, ?Post $post): ?RecommendationCandidate
	{
		if ($post === null
			|| $post->isDeleted()
			|| $post->isArchived()
			|| $post->visibility !== Visibility::Public
		) {
			return null;
		}

		return $this->withLiveFields(
			$candidate,
			$post->content,
			$post->contentWarning,
			$post->publishedAt,
			$post->language === null ? [] : [$post->language],
		);
	}

	private function remoteCandidate(
		RecommendationCandidate $candidate,
		?RemotePost $post,
		string $viewerId,
	): ?RecommendationCandidate {
		if ($post === null
			|| $post->audience !== Visibility::Public
			|| ($this->remoteAccess !== null && !$this->remoteAccess->viewerMayRead($post, $viewerId))
		) {
			return null;
		}

		return $this->withLiveFields(
			$candidate,
			$post->content,
			$post->contentWarning,
			$post->publishedAt,
			[],
		);
	}

	/**
	 * @param list<string> $languages
	 */
	private function withLiveFields(
		RecommendationCandidate $candidate,
		string $content,
		?string $contentWarning,
		DateTimeImmutable $publishedAt,
		array $languages,
	): RecommendationCandidate {
		return new RecommendationCandidate(
			$candidate->id,
			$candidate->targetId,
			$candidate->kind,
			$candidate->authorId,
			$candidate->objectAuthorId,
			$content,
			$contentWarning,
			$publishedAt,
			Visibility::Public,
			array_values($languages),
			$candidate->engagement,
		);
	}
}
