<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * The combined home timeline in SQL.
 *
 * Local posts, local boosts and remote posts are ordered as one union before
 * the limit is applied. Filtering sources separately and merging them in PHP
 * would let a page contain the wrong number of items at a cursor boundary.
 *
 * The remote half scopes ActivityPub and AT Protocol follows to the reader,
 * so unfollowing immediately removes the actor's content without deleting
 * shared remote rows. A reader's `social_block` rows are
 * excluded by signing actor and object author, so a block also hides a boost
 * of the blocked actor's content by somebody else.
 */
final class PdoHomeTimelineRepository implements HomeTimelineRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly ?Clock $clock = null,
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		DateTimeImmutable|TimelineCursor|null $before = null,
		bool $hideReplies = false,
	): array {
		$localCursor = $before === null ? '' : ($before instanceof TimelineCursor
			? ' AND (p.published_at < :local_before OR (p.published_at = :local_before_equal AND p.id < :local_before_id))'
			: ' AND p.published_at < :local_before');
		$boostCursor = $before === null ? '' : ($before instanceof TimelineCursor
			? ' AND (b.created_at < :boost_before OR (b.created_at = :boost_before_equal AND b.id < :boost_before_id))'
			: ' AND b.created_at < :boost_before');
		$remoteCursor = $before === null ? '' : ($before instanceof TimelineCursor
			? ' AND (r.published_at < :remote_before OR (r.published_at = :remote_before_equal AND r.activity_id < :remote_before_id))'
			: ' AND r.published_at < :remote_before');
		$localReplies = $hideReplies
			? ' AND p.in_reply_to_id IS NULL AND p.in_reply_to_remote_object_id IS NULL'
			: '';
		$boostReplies = $hideReplies
			? ' AND p.in_reply_to_id IS NULL AND p.in_reply_to_remote_object_id IS NULL'
			: '';
		$remoteReplies = $hideReplies ? ' AND r.in_reply_to_id IS NULL' : '';

		$sql = 'SELECT item_id, author_id, object_id, object_author_id, content,'
			. ' content_warning, published_at, visibility, boosted_by, in_reply_to_id'
			. ' FROM ('
			. ' SELECT p.id AS item_id, p.author_id AS author_id, p.id AS object_id,'
			. ' p.author_id AS object_author_id, p.content AS content,'
			. ' p.content_warning AS content_warning, p.published_at AS published_at,'
			. ' p.visibility AS visibility, NULL AS boosted_by,'
			. ' COALESCE(p.in_reply_to_id, p.in_reply_to_remote_object_id) AS in_reply_to_id'
			. ' FROM ' . PostSchema::TABLE . ' p'
			. ' WHERE p.deleted_at IS NULL AND p.archived_at IS NULL AND (p.author_id = :local_user'
			. ' OR (p.visibility = :local_direct AND EXISTS ('
			. ' SELECT 1 FROM ' . PostSchema::RECIPIENT_TABLE . ' pr'
			. ' WHERE pr.post_id = p.id AND pr.recipient_id = :local_recipient)))'
			. $localReplies
			. ' AND NOT EXISTS ('
			. ' SELECT 1 FROM ' . MuteSchema::TABLE . ' pm'
			. ' WHERE pm.user_id = :local_mute_user AND pm.actor_id = p.author_id'
			. ' AND (pm.expires_at IS NULL OR pm.expires_at > :local_mute_now)'
			. ')' . $localCursor
			. ' UNION ALL'
			. ' SELECT b.id AS item_id, b.user_id AS author_id, p.id AS object_id, p.author_id AS object_author_id,'
			. ' p.content AS content, p.content_warning AS content_warning, b.created_at AS published_at,'
			. ' p.visibility AS visibility, b.user_id AS boosted_by,'
			. ' COALESCE(p.in_reply_to_id, p.in_reply_to_remote_object_id) AS in_reply_to_id'
			. ' FROM ' . BoostSchema::TABLE . ' b JOIN ' . PostSchema::TABLE . ' p ON p.id = b.post_id'
			. ' WHERE b.user_id = :boost_user AND p.deleted_at IS NULL AND p.archived_at IS NULL'
			. " AND p.visibility IN ('public', 'unlisted')"
			. ' AND NOT EXISTS ('
			. ' SELECT 1 FROM ' . BlockSchema::TABLE . ' bb'
			. ' WHERE bb.local_user_id = :boost_block_user AND bb.actor_id = p.author_id'
			. ')'
			. ' AND NOT EXISTS ('
			. ' SELECT 1 FROM ' . MuteSchema::TABLE . ' bm'
			. ' WHERE bm.user_id = :boost_mute_user AND bm.actor_id = p.author_id'
			. ' AND (bm.expires_at IS NULL OR bm.expires_at > :boost_mute_now)'
			. ')' . $boostReplies . $boostCursor
			. ' UNION ALL'
			. ' SELECT r.activity_id AS item_id, r.actor_id AS author_id, r.object_id AS object_id,'
			. ' r.object_author_id AS object_author_id, r.content AS content,'
			. ' r.content_warning AS content_warning, r.published_at AS published_at,'
			. ' NULL AS visibility,'
			. ' CASE WHEN r.kind = :boost_kind THEN r.actor_id ELSE NULL END AS boosted_by,'
			. ' r.in_reply_to_id AS in_reply_to_id'
			. ' FROM ' . RemotePostSchema::TABLE . ' r'
			. ' WHERE ('
			. ' EXISTS (SELECT 1 FROM ' . FollowingSchema::TABLE . ' f'
			. " WHERE f.actor_id = r.actor_id AND f.local_user_id = :remote_user AND f.state = 'accepted')"
			. ' OR EXISTS (SELECT 1 FROM ' . AtprotoFollowSchema::TABLE . ' af'
			. " WHERE af.subject_did = r.actor_id AND af.local_user_id = :atproto_user AND af.state = 'active')"
			. ')'
			. ' AND r.deleted_at IS NULL'
			. ' AND NOT EXISTS ('
			. ' SELECT 1 FROM ' . BlockSchema::TABLE . ' b'
			. ' WHERE b.local_user_id = :block_user'
			. ' AND (b.actor_id = r.actor_id OR b.actor_id = r.object_author_id)'
			. ')'
			. ' AND NOT EXISTS ('
			. ' SELECT 1 FROM ' . MuteSchema::TABLE . ' rm'
			. ' WHERE rm.user_id = :remote_mute_user'
			. ' AND (rm.actor_id = r.actor_id OR rm.actor_id = r.object_author_id)'
			. ' AND (rm.expires_at IS NULL OR rm.expires_at > :remote_mute_now)'
			. ')' . $remoteReplies . $remoteCursor
			. ') timeline'
			. ' ORDER BY published_at DESC, item_id DESC'
			. ' LIMIT ' . max(1, $limit);

		$muteNow = Time::format(($this->clock ?? new SystemClock())->now());
		$parameters = [
			'local_user' => $localUserId,
			'local_recipient' => $localUserId,
			'local_direct' => Visibility::Direct->value,
			'local_mute_user' => $localUserId,
			'remote_user' => $localUserId,
			'atproto_user' => $localUserId,
			'remote_mute_user' => $localUserId,
			'block_user' => $localUserId,
			'boost_kind' => RemotePostKind::Boost->value,
			'local_mute_now' => $muteNow,
			'remote_mute_now' => $muteNow,
			'boost_user' => $localUserId,
			'boost_block_user' => $localUserId,
			'boost_mute_user' => $localUserId,
			'boost_mute_now' => $muteNow,
		];

		if ($before !== null) {
			$time = $before instanceof TimelineCursor ? $before->publishedAt : $before;
			$parameters['local_before'] = Time::format($time);
			$parameters['boost_before'] = Time::format($time);
			$parameters['remote_before'] = Time::format($time);
			if ($before instanceof TimelineCursor) {
				$parameters['local_before_equal'] = Time::format($time);
				$parameters['boost_before_equal'] = Time::format($time);
				$parameters['remote_before_equal'] = Time::format($time);
				$parameters['local_before_id'] = $before->id;
				$parameters['boost_before_id'] = $before->id;
				$parameters['remote_before_id'] = $before->id;
			}
		}

		$rows = $this->db->fetchAll($sql, $parameters);

		return TimelineEntryDeduplicator::deduplicateBoosts(
			array_map(fn (array $row): TimelineEntry => $this->hydrate($row), $rows),
		);
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
			inReplyToId: $row['in_reply_to_id'] === null ? null : (string) $row['in_reply_to_id'],
		);
	}
}
