<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * The combined home timeline in SQL.
 *
 * Both halves are ordered as one union before the limit is applied. Filtering
 * the local and remote pages separately and merging them in PHP would let a
 * page contain the wrong number of items at a cursor boundary.
 *
 * The remote half joins `social_following` on `actor_id` and scopes the join
 * to the reader, so unfollowing immediately removes the actor's content
 * without deleting the shared remote rows.
 */
final class PdoHomeTimelineRepository implements HomeTimelineRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		$localCursor = $before === null ? '' : ' AND p.published_at < :local_before';
		$remoteCursor = $before === null ? '' : ' AND r.published_at < :remote_before';

		$sql = 'SELECT item_id, author_id, object_id, object_author_id, content,'
			. ' content_warning, published_at, visibility, boosted_by'
			. ' FROM ('
			. ' SELECT p.id AS item_id, p.author_id AS author_id, p.id AS object_id,'
			. ' p.author_id AS object_author_id, p.content AS content,'
			. ' p.content_warning AS content_warning, p.published_at AS published_at,'
			. ' p.visibility AS visibility, NULL AS boosted_by'
			. ' FROM ' . PostSchema::TABLE . ' p'
			. ' WHERE p.deleted_at IS NULL AND p.author_id = :local_user' . $localCursor
			. ' UNION ALL'
			. ' SELECT r.activity_id AS item_id, r.actor_id AS author_id, r.object_id AS object_id,'
			. ' r.object_author_id AS object_author_id, r.content AS content,'
			. ' r.content_warning AS content_warning, r.published_at AS published_at,'
			. ' NULL AS visibility,'
			. ' CASE WHEN r.kind = :boost_kind THEN r.actor_id ELSE NULL END AS boosted_by'
			. ' FROM ' . RemotePostSchema::TABLE . ' r'
			. ' INNER JOIN ' . FollowingSchema::TABLE . ' f ON f.actor_id = r.actor_id'
			. ' WHERE f.local_user_id = :remote_user AND r.deleted_at IS NULL' . $remoteCursor
			. ') timeline'
			. ' ORDER BY published_at DESC, item_id DESC'
			. ' LIMIT ' . max(1, $limit);

		$parameters = [
			'local_user' => $localUserId,
			'remote_user' => $localUserId,
			'boost_kind' => RemotePostKind::Boost->value,
		];

		if ($before !== null) {
			$parameters['local_before'] = Time::format($before);
			$parameters['remote_before'] = Time::format($before);
		}

		$rows = $this->db->fetchAll($sql, $parameters);

		return array_map(fn (array $row): TimelineEntry => $this->hydrate($row), $rows);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): TimelineEntry
	{
		return new TimelineEntry(
			id: (string) $row['item_id'],
			authorId: (string) $row['author_id'],
			objectId: (string) $row['object_id'],
			objectAuthorId: $row['object_author_id'] === null ? null : (string) $row['object_author_id'],
			content: (string) $row['content'],
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			publishedAt: Time::parse((string) $row['published_at']),
			visibility: $row['visibility'] === null ? null : Visibility::from((string) $row['visibility']),
			boostedBy: $row['boosted_by'] === null ? null : (string) $row['boosted_by'],
		);
	}
}
