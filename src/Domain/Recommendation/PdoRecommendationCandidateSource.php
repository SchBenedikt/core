<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_slice;
use function max;
use function min;
use function usort;

/**
 * Reads only enumerable public rows: local `public` posts and cached remote
 * post objects. Unlisted, followers-only and direct posts never enter the
 * candidate pool, and no network request is made while a feed is read.
 */
final class PdoRecommendationCandidateSource implements RecommendationCandidateSource
{
	public const MAX_CANDIDATES = 200;

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function candidates(string $viewerId, int $limit, DateTimeImmutable $now): array
	{
		$limit = max(1, min(self::MAX_CANDIDATES, $limit));

		$local = $this->db->fetchAll(
			'SELECT id, author_id, content, content_warning, published_at FROM ' . PostSchema::TABLE
			. ' WHERE visibility = :visibility AND deleted_at IS NULL AND archived_at IS NULL'
			. ' ORDER BY published_at DESC, id DESC LIMIT ' . $limit,
			['visibility' => Visibility::Public->value],
		);

		// Only a verified public audience may be recommended to arbitrary
		// viewers; followers-only and legacy unknown rows stay out.
		$remote = $this->db->fetchAll(
			'SELECT activity_id, actor_id, object_author_id, content, content_warning, published_at'
			. ' FROM social_remote_post WHERE deleted_at IS NULL AND kind = :kind'
			. ' AND audience = :audience'
			. ' ORDER BY published_at DESC, activity_id DESC LIMIT ' . $limit,
			['kind' => 'post', 'audience' => Visibility::Public->value],
		);

		$candidates = [];
		foreach ($local as $row) {
			$candidates[] = new RecommendationCandidate(
				RecommendationCandidate::localId((string) $row['id']),
				(string) $row['id'],
				RecommendationCandidateKind::Local,
				(string) $row['author_id'],
				null,
				(string) $row['content'],
				$row['content_warning'] === null ? null : (string) $row['content_warning'],
				Time::parse((string) $row['published_at']),
				Visibility::Public,
			);
		}

		foreach ($remote as $row) {
			$candidates[] = new RecommendationCandidate(
				RecommendationCandidate::remoteId((string) $row['activity_id']),
				(string) $row['activity_id'],
				RecommendationCandidateKind::Remote,
				$row['object_author_id'] === null ? (string) $row['actor_id'] : (string) $row['object_author_id'],
				$row['object_author_id'] === null ? null : (string) $row['object_author_id'],
				(string) $row['content'],
				$row['content_warning'] === null ? null : (string) $row['content_warning'],
				Time::parse((string) $row['published_at']),
				Visibility::Public,
			);
		}

		usort($candidates, static fn (RecommendationCandidate $a, RecommendationCandidate $b): int => [$b->publishedAt, $b->id] <=> [$a->publishedAt, $a->id]);

		return array_slice($candidates, 0, $limit);
	}
}
