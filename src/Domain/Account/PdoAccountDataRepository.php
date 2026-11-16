<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsSchema;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Federation\AccountMoveSchema;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Domain\Filter\FilterSchema;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Moderation\HeldPostSchema;
use AlohaSocial\Core\Domain\Moderation\ReportSchema;
use AlohaSocial\Core\Domain\Notification\ConversationMuteSchema;
use AlohaSocial\Core\Domain\Notification\NotificationPolicySchema;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSenderSchema;
use AlohaSocial\Core\Domain\Post\BookmarkSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\QuoteGrantSchema;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSchema;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Domain\Timeline\AccountListSchema;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagSchema;
use AlohaSocial\Core\Platform\Db\Connection;

use function array_keys;
use function hash;
use function sprintf;

/**
 * The SQL adapter for account-wide export and erasure.
 *
 * Every query names an existing table and filters it by an owner column that
 * table already has. There is no archive table and no second persistence:
 * the archive is a read of the live rows, and erasure is the removal of the
 * same rows in one transaction.
 *
 * Tables are processed child-first on deletion. The foreign-key-looking
 * subqueries are deliberate: child tables carry no user column, so they are
 * selected through the parent that does.
 */
final class PdoAccountDataRepository implements AccountDataRepository
{
	/**
	 * @var array<string, array{export: string, delete: string, order: string, redact?: list<string>}>
	 */
	private const TABLES = [
		PostSchema::TABLE => [
			'export' => 'author_id = :user',
			'delete' => 'author_id = :user',
			'order' => 'id',
		],
		PostSchema::ATTACHMENT_TABLE => [
			'export' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, attachment_id',
		],
		PostSchema::RECIPIENT_TABLE => [
			'export' => 'recipient_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'recipient_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, position',
		],
		PostSchema::HASHTAG_TABLE => [
			'export' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, hashtag',
		],
		PostSchema::REVISION_TABLE => [
			'export' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, revision',
		],
		PollSchema::TABLE => [
			'export' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id',
		],
		PollSchema::OPTION_TABLE => [
			'export' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'delete' => 'post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, position',
		],
		PollSchema::VOTE_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'post_id, user_id',
		],
		PostPinSchema::TABLE => [
			'export' => 'owner_id = :user',
			'delete' => 'owner_id = :user',
			'order' => 'owner_id, post_id',
		],
		QuoteGrantSchema::TABLE => [
			'export' => 'actor_id = :actor',
			'delete' => 'actor_id = :actor',
			'order' => 'id',
		],
		FavouriteSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'user_id, post_id',
		],
		BookmarkSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'user_id, post_id',
		],
		BoostSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user OR post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'id',
		],
		ReactionSchema::TABLE => [
			'export' => 'subject_id = :user',
			'delete' => 'subject_id = :user OR local_post_id IN (SELECT id FROM ' . PostSchema::TABLE . ' WHERE author_id = :user)',
			'order' => 'id',
		],
		RemotePollSchema::VOTE_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'object_id, user_id',
		],
		StorySchema::TABLE => [
			'export' => 'owner_id = :user',
			'delete' => 'owner_id = :user',
			'order' => 'id',
		],
		StorySchema::VIEW_TABLE => [
			'export' => 'viewer_id = :user',
			'delete' => 'viewer_id = :user OR story_id IN (SELECT id FROM ' . StorySchema::TABLE . ' WHERE owner_id = :user)',
			'order' => 'story_id, viewer_id',
		],
		StorySchema::REACTION_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user OR story_id IN (SELECT id FROM ' . StorySchema::TABLE . ' WHERE owner_id = :user)',
			'order' => 'story_id, user_id, emoji',
		],
		StorySchema::REPLY_TABLE => [
			'export' => 'author_id = :user',
			'delete' => 'author_id = :user OR story_id IN (SELECT id FROM ' . StorySchema::TABLE . ' WHERE owner_id = :user)',
			'order' => 'story_id, id',
		],
		StorySchema::TOMBSTONE_TABLE => [
			'export' => 'owner_id = :user',
			'delete' => 'owner_id = :user',
			'order' => 'object_id',
		],
		FollowingSchema::TABLE => [
			'export' => 'local_user_id = :user',
			'delete' => 'local_user_id = :user',
			'order' => 'local_user_id, actor_id',
		],
		FollowerSchema::TABLE => [
			'export' => 'local_user_id = :user',
			'delete' => 'local_user_id = :user',
			'order' => 'local_user_id, actor_id',
		],
		BlockSchema::TABLE => [
			'export' => 'local_user_id = :user',
			'delete' => 'local_user_id = :user',
			'order' => 'local_user_id, actor_id',
		],
		MuteSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id, actor_id',
		],
		AccountMoveSchema::TABLE => [
			'export' => 'origin_actor_id = :actor',
			'delete' => 'origin_actor_id = :actor',
			'order' => 'event_id',
		],
		AccountListSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'id',
		],
		AccountListSchema::MEMBER_TABLE => [
			'export' => 'account_id = :user OR list_id IN (SELECT id FROM ' . AccountListSchema::TABLE . ' WHERE user_id = :user)',
			'delete' => 'account_id = :user OR list_id IN (SELECT id FROM ' . AccountListSchema::TABLE . ' WHERE user_id = :user)',
			'order' => 'list_id, account_id',
		],
		FollowedHashtagSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id, hashtag',
		],
		FilterSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'id',
		],
		NotificationSchema::TABLE => [
			'export' => 'recipient_id = :user',
			'delete' => 'recipient_id = :user',
			'order' => 'id',
		],
		NotificationSenderSchema::TABLE => [
			'export' => 'recipient_id = :user',
			'delete' => 'recipient_id = :user',
			'order' => 'id',
		],
		NotificationPolicySchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id',
		],
		ConversationMuteSchema::TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id, conversation_id',
		],
		ReportSchema::TABLE => [
			'export' => 'reporter_id = :user',
			'delete' => 'reporter_id = :user',
			'order' => 'id',
		],
		HeldPostSchema::TABLE => [
			'export' => 'author_id = :user',
			'delete' => 'author_id = :user',
			'order' => 'id',
		],
		AttachmentSchema::TABLE => [
			'export' => 'owner_id = :user',
			'delete' => 'owner_id = :user',
			'order' => 'id',
		],
		RecommendationSchema::PREFERENCE_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id',
		],
		RecommendationSchema::FEEDBACK_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'user_id, candidate_id',
		],
		RecommendationSchema::SIGNAL_TABLE => [
			'export' => 'user_id = :user',
			'delete' => 'user_id = :user',
			'order' => 'id',
		],
		SearchIndexSchema::TABLE => [
			'export' => 'author_id = :user',
			'delete' => 'author_id = :user',
			'order' => 'id',
		],
		ScheduledPostSchema::TABLE => [
			'export' => 'author_id = :user',
			'delete' => 'author_id = :user',
			'order' => 'id',
		],
		MediaCollectionSchema::TABLE => [
			'export' => 'owner_id = :user',
			'delete' => 'owner_id = :user',
			'order' => 'id',
		],
		MediaCollectionSchema::ITEM_TABLE => [
			'export' => 'collection_id IN (SELECT id FROM ' . MediaCollectionSchema::TABLE . ' WHERE owner_id = :user)',
			'delete' => 'collection_id IN (SELECT id FROM ' . MediaCollectionSchema::TABLE . ' WHERE owner_id = :user)',
			'order' => 'collection_id, post_id',
		],
		AtprotoPublicationSchema::TABLE => [
			'export' => 'local_user_id = :user',
			'delete' => 'local_user_id = :user',
			'order' => 'local_user_id, post_id',
		],
	];

	/**
	 * Child tables before their parent. The order is also the order in the
	 * erasure report, so an operator can see which relation was touched.
	 */
	private const DELETE_ORDER = [
		MediaCollectionSchema::ITEM_TABLE,
		MediaCollectionSchema::TABLE,
		PostSchema::ATTACHMENT_TABLE,
		PostSchema::RECIPIENT_TABLE,
		PostSchema::HASHTAG_TABLE,
		PostSchema::REVISION_TABLE,
		PollSchema::VOTE_TABLE,
		PollSchema::OPTION_TABLE,
		PollSchema::TABLE,
		ReactionSchema::TABLE,
		PostPinSchema::TABLE,
		StorySchema::VIEW_TABLE,
		StorySchema::REACTION_TABLE,
		StorySchema::REPLY_TABLE,
		StorySchema::TOMBSTONE_TABLE,
		StorySchema::TABLE,
		AccountListSchema::MEMBER_TABLE,
		AccountListSchema::TABLE,
		NotificationSenderSchema::TABLE,
		NotificationSchema::TABLE,
		NotificationPolicySchema::TABLE,
		ConversationMuteSchema::TABLE,
		ReportSchema::TABLE,
		HeldPostSchema::TABLE,
		SearchIndexSchema::TABLE,
		ScheduledPostSchema::TABLE,
		AtprotoPublicationSchema::TABLE,
		AttachmentSchema::TABLE,
		FavouriteSchema::TABLE,
		BookmarkSchema::TABLE,
		BoostSchema::TABLE,
		RemotePollSchema::VOTE_TABLE,
		QuoteGrantSchema::TABLE,
		FollowingSchema::TABLE,
		FollowerSchema::TABLE,
		BlockSchema::TABLE,
		MuteSchema::TABLE,
		FollowedHashtagSchema::TABLE,
		FilterSchema::TABLE,
		RecommendationSchema::PREFERENCE_TABLE,
		RecommendationSchema::FEEDBACK_TABLE,
		RecommendationSchema::SIGNAL_TABLE,
		AccountMoveSchema::TABLE,
		// The post row is removed last: interactions on the account's posts
		// select through it, so deleting it earlier would leave them behind.
		PostSchema::TABLE,
	];

	public function __construct(
		private readonly Connection $database,
	) {
	}

	public function snapshot(string $userId, string $actorId): AccountDataSnapshot
	{
		$tables = [];
		foreach (array_keys(self::TABLES) as $table) {
			if (!$this->tableExists($table)) {
				continue;
			}

			$spec = self::TABLES[$table];
			$rows = $this->database->fetchAll(
				'SELECT * FROM ' . $table . ' WHERE ' . $spec['export'] . ' ORDER BY ' . $spec['order'],
				$this->parametersFor($spec['export'], $userId, $actorId),
			);

			if (isset($spec['redact'])) {
				foreach ($rows as $index => $row) {
					foreach ($spec['redact'] as $column) {
						unset($rows[$index][$column]);
					}
				}
			}

			$tables[$table] = $rows;
		}

		$pds = $this->snapshotPds(['user' => $userId]);
		foreach ($pds as $table => $rows) {
			$tables[$table] = $rows;
		}

		return new AccountDataSnapshot($tables, $this->blobPaths($userId));
	}

	public function erase(string $userId, string $actorId): AccountDataErasure
	{
		return $this->database->transaction(function () use ($userId, $actorId): AccountDataErasure {
			// Collect paths before deleting the metadata rows that name them.
			$blobPaths = $this->blobPaths($userId);
			$counts = [];

			foreach (self::DELETE_ORDER as $table) {
				if (!$this->tableExists($table)) {
					continue;
				}

				$spec = self::TABLES[$table];
				$counts[$table] = $this->database->execute(
					'DELETE FROM ' . $table . ' WHERE ' . $spec['delete'],
					$this->parametersFor($spec['delete'], $userId, $actorId),
				);
			}

			$counts = $this->erasePds($counts, ['user' => $userId]);

			return new AccountDataErasure($counts, $blobPaths);
		});
	}

	/**
	 * @param array{user: string} $parameters
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function snapshotPds(array $parameters): array
	{
		if (!$this->tableExists(PdsSchema::IDENTITY_TABLE)) {
			return [];
		}

		$tables = [
			PdsSchema::IDENTITY_TABLE => $this->database->fetchAll(
				'SELECT * FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE local_user_id = :user ORDER BY local_user_id',
				$parameters,
			),
		];

		$byDid = [
			PdsSchema::KEY_TABLE => 'id',
			PdsSchema::RECORD_TABLE => 'did, collection, rkey',
			PdsSchema::COMMIT_TABLE => 'did, cid',
			PdsSchema::BLOCK_TABLE => 'did, cid',
			PdsSchema::PLC_TABLE => 'did, cid',
			PdsSchema::BLOB_TABLE => 'did, cid',
			PdsSchema::EVENT_TABLE => 'seq',
		];

		$didScope = 'did IN (SELECT did FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE local_user_id = :user)';
		foreach ($byDid as $table => $order) {
			if (!$this->tableExists($table)) {
				continue;
			}

			$rows = $this->database->fetchAll(
				'SELECT * FROM ' . $table . ' WHERE ' . $didScope . ' ORDER BY ' . $order,
				$parameters,
			);

			if ($table === PdsSchema::KEY_TABLE) {
				// A sealed private seed is server material, not a useful user
				// export. Public key metadata and the key id remain.
				foreach ($rows as $index => $row) {
					unset($rows[$index]['private_key_sealed']);
				}
			}

			$tables[$table] = $rows;
		}

		return $tables;
	}

	/**
	 * @param array<string, int> $counts
	 * @param array{user: string} $parameters
	 * @return array<string, int>
	 */
	private function erasePds(array $counts, array $parameters): array
	{
		if (!$this->tableExists(PdsSchema::IDENTITY_TABLE)) {
			return $counts;
		}

		$didScope = 'did IN (SELECT did FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE local_user_id = :user)';
		foreach ([
			PdsSchema::KEY_TABLE,
			PdsSchema::RECORD_TABLE,
			PdsSchema::COMMIT_TABLE,
			PdsSchema::BLOCK_TABLE,
			PdsSchema::PLC_TABLE,
			PdsSchema::BLOB_TABLE,
			PdsSchema::EVENT_TABLE,
		] as $table) {
			if (!$this->tableExists($table)) {
				continue;
			}

			$counts[$table] = $this->database->execute(
				'DELETE FROM ' . $table . ' WHERE ' . $didScope,
				$parameters,
			);
		}

		$counts[PdsSchema::IDENTITY_TABLE] = $this->database->execute(
			'DELETE FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE local_user_id = :user',
			$parameters,
		);

		return $counts;
	}

	/**
	 * @return list<string>
	 */
	private function blobPaths(string $userId): array
	{
		$paths = [];
		if ($this->tableExists(AttachmentSchema::TABLE)) {
			$rows = $this->database->fetchAll(
				'SELECT storage_key, thumbnail_key FROM ' . AttachmentSchema::TABLE . ' WHERE owner_id = :user',
				['user' => $userId],
			);
			foreach ($rows as $row) {
				foreach (['storage_key', 'thumbnail_key'] as $column) {
					$path = (string) ($row[$column] ?? '');
					if ($path !== '') {
						$paths[] = $path;
					}
				}
			}
		}

		if ($this->tableExists(PdsSchema::IDENTITY_TABLE) && $this->tableExists(PdsSchema::BLOB_TABLE)) {
			$rows = $this->database->fetchAll(
				'SELECT did, cid FROM ' . PdsSchema::BLOB_TABLE
				. ' WHERE did IN (SELECT did FROM ' . PdsSchema::IDENTITY_TABLE
				. ' WHERE local_user_id = :user)',
				['user' => $userId],
			);
			foreach ($rows as $row) {
				$paths[] = sprintf(
					'pds/blobs/%s/%s.bin',
					hash('sha256', (string) $row['did']),
					(string) $row['cid'],
				);
			}
		}

		return $paths;
	}

	/**
	 * @return array<string, string>
	 */
	private function parametersFor(string $where, string $userId, string $actorId): array
	{
		$parameters = [];
		if (str_contains($where, ':user')) {
			$parameters['user'] = $userId;
		}
		if (str_contains($where, ':actor')) {
			$parameters['actor'] = $actorId;
		}

		return $parameters;
	}

	private function tableExists(string $table): bool
	{
		try {
			return match ($this->database->driver()) {
				'sqlite' => $this->database->fetchValue(
					"SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table",
					['table' => $table],
				) !== null,
				'mysql' => $this->database->fetchValue(
					'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
					['table' => $table],
				) !== null,
				'pgsql' => $this->database->fetchValue(
					'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
					['table' => $table],
				) !== null,
				default => true,
			};
		} catch (\Throwable) {
			// An installation may have been created before an optional schema
			// existed. A missing table is not an account-data failure.
			return false;
		}
	}
}
