<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_slice;
use function max;
use function mb_strtolower;
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

		// Engagement is a bounded provenance count over the local tables:
		// favourites, boosts, stored reactions and non-deleted replies. Remote
		// candidates keep engagement 0 rather than inventing peer numbers.
		$local = $this->db->fetchAll(
			'SELECT p.id, p.author_id, p.content, p.content_warning, p.published_at, p.language,'
			. ' (SELECT COUNT(*) FROM ' . FavouriteSchema::TABLE . ' f WHERE f.post_id = p.id)'
			. ' + (SELECT COUNT(*) FROM ' . BoostSchema::TABLE . ' b WHERE b.post_id = p.id)'
			. ' + (SELECT COUNT(*) FROM ' . ReactionSchema::TABLE . ' r WHERE r.local_post_id = p.id)'
			. ' + (SELECT COUNT(*) FROM ' . PostSchema::TABLE . ' c'
			. ' WHERE c.in_reply_to_id = p.id AND c.deleted_at IS NULL) AS engagement'
			. ' FROM ' . PostSchema::TABLE . ' p'
			. ' WHERE p.visibility = :visibility AND p.deleted_at IS NULL AND p.archived_at IS NULL'
			. ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit,
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
				$row['language'] === null ? [] : [mb_strtolower((string) $row['language'])],
				(int) $row['engagement'],
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
