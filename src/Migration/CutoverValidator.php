<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function array_keys;
use function count;
use function ksort;
use function max;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function trim;

/**
 * Read-only validator for the neutral JSON export of a migration cutover.
 *
 * It never reads a database and never writes anything. The host exports its
 * old identities and the rows that are about to be served by the core; this
 * class derives the core's identities from the target rows and compares them
 * with the old values.
 *
 * Export format (version 1):
 *
 * {
 *   "format": "alohasocial.migration-cutover",
 *   "version": 1,
 *   "instance_url": "https://social.example",
 *   "accounts": [{
 *     "legacy_user_id": "alice",
 *     "local_user_id": "alice",
 *     "actor_url": "https://social.example/@alice",
 *     "key_id": "https://social.example/@alice#main-key"
 *   }],
 *   "posts": [{
 *     "legacy_id": "post-1",
 *     "id": "post-1",
 *     "author_id": "alice",
 *     "content": "Hello",
 *     "content_warning": null,
 *     "visibility": "public",
 *     "published_at": "2026-11-11T10:00:00Z",
 *     "deleted_at": null,
 *     "note_url": "https://social.example/api/v1/posts/post-1",
 *     "create_id": "https://social.example/api/v1/posts/post-1/activity",
 *     "delete_id": "https://social.example/api/v1/posts/post-1/delete"
 *   }],
 *   "followers": [{
 *     "legacy_id": "follower-1",
 *     "id": "follower-1",
 *     "local_user_id": "alice",
 *     "actor_id": "https://remote.example/users/bob",
 *     "inbox_url": "https://remote.example/users/bob/inbox",
 *     "followed_at": "2026-11-11T10:00:00Z"
 *   }],
 *   "following": [{
 *     "legacy_id": "follow-1",
 *     "id": "follow-1",
 *     "local_user_id": "alice",
 *     "actor_id": "https://remote.example/users/bob",
 *     "inbox_url": "https://remote.example/users/bob/inbox",
 *     "followed_at": "2026-11-11T10:00:00Z",
 *     "follow_id": "https://social.example/api/v1/following/follow-1/follow",
 *     "undo_id": "https://social.example/api/v1/following/follow-1/follow/undo"
 *   }],
 *   "queue": [{
 *     "id": "job-1",
 *     "type": "federation.deliver",
 *     "activity_type": "Create",
 *     "activity_id": "https://social.example/api/v1/posts/post-1/activity",
 *     "inbox": "https://remote.example/users/bob/inbox",
 *     "key_id": "https://social.example/@alice#main-key",
 *     "available_at": "2026-11-11T10:00:00Z",
 *     "attempts": 0,
 *     "dead_at": null
 *   }],
 *   "activity_log": [{
 *     "activity_id": "https://remote.example/activities/1",
 *     "seen_at": "2026-11-11T10:00:00Z"
 *   }]
 * }
 *
 * For accounts, posts and following edges, `legacy_*` and `id` must describe
 * the same entity. The validator compares them explicitly and also derives the
 * expected ActivityPub ids from `id`; this is what makes a changed
 * `following.id` visible even when all URLs were regenerated from the new id.
 * Follower row ids are internal; only `followers[].id` is used for references,
 * so a host may assign new stable ids for them.
 *
 * Version 2 keeps every version-1 field and meaning and adds optional
 * per-feature sections for records that already exist in the source but had no
 * neutral export representation: `revisions`, `media`, `reactions`, `quotes`
 * and `counts`. A version-1 export validates exactly as before; a version-2
 * export additionally cross-checks revision histories against the live
 * revision, attachment links against the media rows, media checksums, reaction
 * identity keys and declared per-feature counts.
 *
 * Findings are categorized: a `fail` blocks the cutover, a `warn` records a
 * semantic difference the export cannot resolve (a dead-lettered job, media
 * without a checksum) without blocking. Version 1 never produces warnings.
 *
 * The feature-level inventory that says which source entity has which core
 * destination is {@see MigrationInventory}; a feature without a destination is
 * listed there as a gap instead of being silently dropped here.
 *
 * The human-readable format is documented in
 * {@see docs/guides/migration-from-nextcloud.md#export-format}.
 */
final class CutoverValidator
{
	public const FORMAT = 'alohasocial.migration-cutover';

	/** The oldest supported export version; its semantics never change. */
	public const VERSION = 1;

	/** @var list<int> */
	public const SUPPORTED_VERSIONS = [1, 2];

	private const ACTIVITY_TYPES = ['Create', 'Delete', 'Follow', 'Undo', 'Accept'];

	/**
	 * @throws InvalidMigrationDatasetException when the file cannot be read
	 */
	public function __invoke(string $path): MigrationReport
	{
		return $this->validateFile($path);
	}

	/**
	 * @throws InvalidMigrationDatasetException when the file cannot be read or parsed
	 */
	public function validateFile(string $path): MigrationReport
	{
		$json = @file_get_contents($path);
		if ($json === false) {
			throw new InvalidMigrationDatasetException('Cannot read migration export: ' . $path);
		}

		return $this->validateJson($json, $path);
	}

	/**
	 * @throws InvalidMigrationDatasetException when the JSON or its structure is invalid
	 */
	public function validateJson(string $json, string $source = '<memory>'): MigrationReport
	{
		try {
			$dataset = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new InvalidMigrationDatasetException('Invalid JSON: ' . $exception->getMessage(), 0, $exception);
		}

		if (!is_array($dataset) || array_is_list($dataset)) {
			throw new InvalidMigrationDatasetException('The migration export must be a JSON object.');
		}

		if (($dataset['format'] ?? null) !== self::FORMAT) {
			throw new InvalidMigrationDatasetException(
				'Unsupported format; expected "' . self::FORMAT . '".',
			);
		}

		$version = $dataset['version'] ?? null;
		if (!is_int($version) || !in_array($version, self::SUPPORTED_VERSIONS, true)) {
			throw new InvalidMigrationDatasetException(
				'Unsupported version; expected one of ' . implode(', ', self::SUPPORTED_VERSIONS) . '.',
			);
		}

		$instanceUrl = $this->requiredString($dataset, 'instance_url', 'dataset');
		if (!$this->isHttpUrl($instanceUrl)) {
			throw new InvalidMigrationDatasetException(
				'dataset.instance_url: expected an absolute http(s) URL.',
			);
		}

		try {
			$baseUrl = (new ActivityFactory(new ArrayConfig([
				'social' => [ActivityFactory::CONFIG_URL => $instanceUrl],
			])))->baseUrl();
		} catch (ConfigurationException $exception) {
			throw new InvalidMigrationDatasetException(
				'dataset.instance_url: ' . $exception->getMessage(),
				0,
				$exception,
			);
		}

		$activities = new ActivityFactory(new ArrayConfig([
			'social' => [ActivityFactory::CONFIG_URL => $baseUrl],
		]));

		/** @var list<MigrationViolation> $violations */
		$violations = [];
		/** @var array<string, string> $accountKeys */
		$accountKeys = [];
		/** @var array<string, true> $postIds */
		$postIds = [];
		/** @var array<string, string> $createIds */
		$createIds = [];
		/** @var array<string, string> $deleteIds */
		$deleteIds = [];
		/** @var array<string, true> $followerIds */
		$followerIds = [];
		/** @var array<string, true> $followerPairs */
		$followerPairs = [];
		/** @var array<string, true> $followingIds */
		$followingIds = [];
		/** @var array<string, true> $followingPairs */
		$followingPairs = [];
		/** @var array<string, string> $followIds */
		$followIds = [];
		/** @var array<string, string> $undoIds */
		$undoIds = [];
		/** @var array<string, true> $queueIds */
		$queueIds = [];
		/** @var array<string, true> $activityIds */
		$activityIds = [];
		/** @var array<string, array{record: string, revision: ?int, attachment_ids: list<string>}> $postMeta */
		$postMeta = [];

		foreach ($this->records($dataset, 'accounts') as $index => $entry) {
			$this->validateAccount(
				$activities,
				$this->record($entry, 'accounts[' . $index . ']'),
				'accounts[' . $index . ']',
				$violations,
				$accountKeys,
			);
		}

		foreach ($this->records($dataset, 'posts') as $index => $entry) {
			$this->validatePost(
				$activities,
				$this->record($entry, 'posts[' . $index . ']'),
				'posts[' . $index . ']',
				$violations,
				$accountKeys,
				$postIds,
				$createIds,
				$deleteIds,
				$postMeta,
			);
		}

		foreach ($this->records($dataset, 'followers') as $index => $entry) {
			$this->validateFollower(
				$this->record($entry, 'followers[' . $index . ']'),
				'followers[' . $index . ']',
				$violations,
				$accountKeys,
				$followerIds,
				$followerPairs,
			);
		}

		foreach ($this->records($dataset, 'following') as $index => $entry) {
			$this->validateFollowing(
				$activities,
				$this->record($entry, 'following[' . $index . ']'),
				'following[' . $index . ']',
				$violations,
				$accountKeys,
				$followingIds,
				$followingPairs,
				$followIds,
				$undoIds,
			);
		}

		foreach ($this->records($dataset, 'queue') as $index => $entry) {
			$this->validateQueue(
				$this->record($entry, 'queue[' . $index . ']'),
				'queue[' . $index . ']',
				$violations,
				$accountKeys,
				$queueIds,
				$createIds,
				$deleteIds,
				$followIds,
				$undoIds,
				$version >= 2,
			);
		}

		foreach ($this->records($dataset, 'activity_log') as $index => $entry) {
			$this->validateActivityLog(
				$this->record($entry, 'activity_log[' . $index . ']'),
				'activity_log[' . $index . ']',
				$violations,
				$activityIds,
			);
		}

		if ($version >= 2) {
			$this->validateVersionTwo(
				$dataset,
				$violations,
				$postIds,
				$postMeta,
				[
					'accounts' => count($this->records($dataset, 'accounts')),
					'posts' => count($this->records($dataset, 'posts')),
					'followers' => count($this->records($dataset, 'followers')),
					'following' => count($this->records($dataset, 'following')),
					'queue' => count($this->records($dataset, 'queue')),
					'activity_log' => count($this->records($dataset, 'activity_log')),
				],
			);
		}

		return new MigrationReport($source, $violations);
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, string> $accountKeys
	 */
	private function validateAccount(
		ActivityFactory $activities,
		array $row,
		string $record,
		array &$violations,
		array &$accountKeys,
	): void {
		$legacyId = $this->requiredString($row, 'legacy_user_id', $record);
		$localId = $this->requiredString($row, 'local_user_id', $record);
		$actorUrl = $this->requiredString($row, 'actor_url', $record);
		$keyId = $this->requiredString($row, 'key_id', $record);

		$this->checkLength($record, 'local_user_id', $localId, 255, $violations);
		$this->checkLength($record, 'actor_url', $actorUrl, 512, $violations);
		$this->checkLength($record, 'key_id', $keyId, 512, $violations);

		if ($legacyId !== $localId) {
			$violations[] = new MigrationViolation(
				$record,
				'local_user_id',
				'The local user id changed; actor URLs are derived from it.',
				$legacyId,
				$localId,
			);
		}

		if (array_key_exists($localId, $accountKeys)) {
			$violations[] = new MigrationViolation(
				$record,
				'local_user_id',
				'Duplicate local user id in the export.',
				null,
				$localId,
			);
		}

		$this->compare(
			$record,
			'actor_url',
			'Actor URL does not match the identity derived from the instance and user id.',
			$activities->actorId($localId),
			$actorUrl,
			$violations,
		);
		$this->compare(
			$record,
			'key_id',
			'Key id does not match the actor key id.',
			$activities->keyId($localId),
			$keyId,
			$violations,
		);

		$accountKeys[$localId] = $keyId;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, string> $accountKeys
	 * @param array<string, true>   $postIds
	 * @param array<string, string> $createIds
	 * @param array<string, string> $deleteIds
	 * @param array<string, array{record: string, revision: ?int, attachment_ids: list<string>}> $postMeta
	 */
	private function validatePost(
		ActivityFactory $activities,
		array $row,
		string $record,
		array &$violations,
		array $accountKeys,
		array &$postIds,
		array &$createIds,
		array &$deleteIds,
		array &$postMeta,
	): void {
		$legacyId = $this->requiredString($row, 'legacy_id', $record);
		$id = $this->requiredString($row, 'id', $record);
		$authorId = $this->requiredString($row, 'author_id', $record);
		$content = $this->stringValue($row, 'content', $record);
		$contentWarning = $this->nullableString($row, 'content_warning', $record);
		$visibilityValue = $this->requiredString($row, 'visibility', $record);
		$publishedValue = $this->requiredString($row, 'published_at', $record);
		$deletedValue = $this->nullableString($row, 'deleted_at', $record);
		$noteUrl = $this->requiredString($row, 'note_url', $record);
		$createId = $this->requiredString($row, 'create_id', $record);
		$deleteId = $this->requiredString($row, 'delete_id', $record);

		$this->checkLength($record, 'id', $id, 64, $violations);
		$this->checkLength($record, 'note_url', $noteUrl, 1024, $violations);
		$this->checkLength($record, 'create_id', $createId, 1024, $violations);
		$this->checkLength($record, 'delete_id', $deleteId, 1024, $violations);

		if ($legacyId !== $id) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'The post id changed; note and activity ids are derived from it.',
				$legacyId,
				$id,
			);
		}

		if (array_key_exists($id, $postIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'Duplicate post id in the export.',
				null,
				$id,
			);
		}

		if (!array_key_exists($authorId, $accountKeys)) {
			$violations[] = new MigrationViolation(
				$record,
				'author_id',
				'Post points at an account that is not in the export.',
				'a known local_user_id',
				$authorId,
			);
		}

		try {
			$visibility = Visibility::fromString($visibilityValue);
		} catch (InvalidArgumentException $exception) {
			$violations[] = new MigrationViolation(
				$record,
				'visibility',
				$exception->getMessage(),
				null,
				$visibilityValue,
			);

			return;
		}

		$publishedAt = $this->timestamp($publishedValue, $record, 'published_at', $violations);
		if ($publishedAt === null) {
			return;
		}

		$deletedAt = null;
		if ($deletedValue !== null) {
			$deletedAt = $this->timestamp($deletedValue, $record, 'deleted_at', $violations);
			if ($deletedAt === null) {
				return;
			}
		}

		$post = new Post(
			id: $id,
			authorId: $authorId,
			content: $content,
			visibility: $visibility,
			publishedAt: $publishedAt,
			contentWarning: $contentWarning,
			deletedAt: $deletedAt,
		);

		$this->compare(
			$record,
			'note_url',
			'Post URL does not match the URL derived from the post id.',
			$activities->noteId($post),
			$noteUrl,
			$violations,
		);
		$this->compare(
			$record,
			'create_id',
			'Create activity id does not match the post URL.',
			$activities->createNote($post)['id'],
			$createId,
			$violations,
		);
		$this->compare(
			$record,
			'delete_id',
			'Delete activity id does not match the post URL.',
			$activities->deleteNote($post, $deletedAt ?? $publishedAt)['id'],
			$deleteId,
			$violations,
		);

		$postIds[$id] = true;
		$postMeta[$id] = [
			'record' => $record,
			'revision' => $this->postRevision($row, $record, $violations),
			'attachment_ids' => $this->attachmentIds($row, $record, $violations),
		];
		$createIds[$createId] = $id;
		$deleteIds[$deleteId] = $id;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, string> $accountKeys
	 * @param array<string, true>   $followerIds
	 * @param array<string, true>   $followerPairs
	 */
	private function validateFollower(
		array $row,
		string $record,
		array &$violations,
		array $accountKeys,
		array &$followerIds,
		array &$followerPairs,
	): void {
		// Follower row ids are internal: no federation identity is derived from
		// them, so a host may choose new stable ids. `legacy_id` stays in the
		// export for traceability and schema compatibility, not as an identity
		// assertion.
		$this->requiredString($row, 'legacy_id', $record);
		$id = $this->requiredString($row, 'id', $record);
		$localUserId = $this->requiredString($row, 'local_user_id', $record);
		$actorId = $this->requiredString($row, 'actor_id', $record);
		$inboxUrl = $this->requiredString($row, 'inbox_url', $record);
		$followedValue = $this->requiredString($row, 'followed_at', $record);

		$this->checkLength($record, 'id', $id, 64, $violations);
		$this->checkLength($record, 'actor_id', $actorId, 255, $violations);
		$this->checkLength($record, 'inbox_url', $inboxUrl, 512, $violations);

		if (array_key_exists($id, $followerIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'Duplicate follower row id in the export.',
				null,
				$id,
			);
		}

		if (!array_key_exists($localUserId, $accountKeys)) {
			$violations[] = new MigrationViolation(
				$record,
				'local_user_id',
				'Follower points at an account that is not in the export.',
				'a known local_user_id',
				$localUserId,
			);
		}

		$this->requireHttpUrl($record, 'actor_id', $actorId, $violations);
		$this->requireHttpUrl($record, 'inbox_url', $inboxUrl, $violations);
		$this->timestamp($followedValue, $record, 'followed_at', $violations);

		$pair = $localUserId . "\0" . $actorId;
		if (array_key_exists($pair, $followerPairs)) {
			$violations[] = new MigrationViolation(
				$record,
				'actor_id',
				'Duplicate (local_user_id, actor_id) follower pair in the export.',
				null,
				$actorId,
			);
		}

		$followerIds[$id] = true;
		$followerPairs[$pair] = true;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, string> $accountKeys
	 * @param array<string, true>   $followingIds
	 * @param array<string, true>   $followingPairs
	 * @param array<string, string> $followIds
	 * @param array<string, string> $undoIds
	 */
	private function validateFollowing(
		ActivityFactory $activities,
		array $row,
		string $record,
		array &$violations,
		array $accountKeys,
		array &$followingIds,
		array &$followingPairs,
		array &$followIds,
		array &$undoIds,
	): void {
		$legacyId = $this->requiredString($row, 'legacy_id', $record);
		$id = $this->requiredString($row, 'id', $record);
		$localUserId = $this->requiredString($row, 'local_user_id', $record);
		$actorId = $this->requiredString($row, 'actor_id', $record);
		$inboxUrl = $this->requiredString($row, 'inbox_url', $record);
		$followedValue = $this->requiredString($row, 'followed_at', $record);
		$followId = $this->requiredString($row, 'follow_id', $record);
		$undoId = $this->requiredString($row, 'undo_id', $record);

		$this->checkLength($record, 'id', $id, 64, $violations);
		$this->checkLength($record, 'actor_id', $actorId, 255, $violations);
		$this->checkLength($record, 'inbox_url', $inboxUrl, 512, $violations);
		$this->checkLength($record, 'follow_id', $followId, 1024, $violations);
		$this->checkLength($record, 'undo_id', $undoId, 1024, $violations);

		if ($legacyId !== $id) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'The outgoing follow row id changed; a later Undo will not match the accepted Follow.',
				$legacyId,
				$id,
			);
		}

		if (array_key_exists($id, $followingIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'Duplicate outgoing follow row id in the export.',
				null,
				$id,
			);
		}

		if (!array_key_exists($localUserId, $accountKeys)) {
			$violations[] = new MigrationViolation(
				$record,
				'local_user_id',
				'Following edge points at an account that is not in the export.',
				'a known local_user_id',
				$localUserId,
			);
		}

		$this->requireHttpUrl($record, 'actor_id', $actorId, $violations);
		$this->requireHttpUrl($record, 'inbox_url', $inboxUrl, $violations);
		$followedAt = $this->timestamp($followedValue, $record, 'followed_at', $violations);
		if ($followedAt === null) {
			return;
		}

		$followed = new FollowedActor(
			id: $id,
			localUserId: $localUserId,
			actorId: $actorId,
			inboxUrl: $inboxUrl,
			followedAt: $followedAt,
		);

		$this->compare(
			$record,
			'follow_id',
			'Follow activity id does not match the following row id.',
			$activities->followId($followed->id),
			$followId,
			$violations,
		);
		$this->compare(
			$record,
			'undo_id',
			'Undo activity id does not match the following row id.',
			$activities->undoFollow($localUserId, $followed)['id'],
			$undoId,
			$violations,
		);

		$pair = $localUserId . "\0" . $actorId;
		if (array_key_exists($pair, $followingPairs)) {
			$violations[] = new MigrationViolation(
				$record,
				'actor_id',
				'Duplicate (local_user_id, actor_id) following pair in the export.',
				null,
				$actorId,
			);
		}

		$followingIds[$id] = true;
		$followingPairs[$pair] = true;
		$followIds[$followId] = $id;
		$undoIds[$undoId] = $id;
	}

	/**
	 * @param array<string, mixed>  $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, string> $accountKeys
	 * @param array<string, true>   $queueIds
	 * @param array<string, string> $createIds
	 * @param array<string, string> $deleteIds
	 * @param array<string, string> $followIds
	 * @param array<string, string> $undoIds
	 * @param bool $warnOnDeadJob whether version-2 semantics may add warnings
	 */
	private function validateQueue(
		array $row,
		string $record,
		array &$violations,
		array $accountKeys,
		array &$queueIds,
		array $createIds,
		array $deleteIds,
		array $followIds,
		array 		$undoIds,
		bool $warnOnDeadJob = false,
	): void {
		$id = $this->requiredString($row, 'id', $record);
		$type = $this->requiredString($row, 'type', $record);
		$activityType = $this->requiredString($row, 'activity_type', $record);
		$activityId = $this->requiredString($row, 'activity_id', $record);
		$inbox = $this->requiredString($row, 'inbox', $record);
		$keyId = $this->requiredString($row, 'key_id', $record);
		$availableValue = $this->requiredString($row, 'available_at', $record);
		$attempts = $this->requiredInt($row, 'attempts', $record);
		$deadValue = $this->nullableString($row, 'dead_at', $record);

		$this->checkLength($record, 'id', $id, 64, $violations);
		$this->checkLength($record, 'activity_id', $activityId, 1024, $violations);
		$this->checkLength($record, 'inbox', $inbox, 512, $violations);
		$this->checkLength($record, 'key_id', $keyId, 512, $violations);

		if (array_key_exists($id, $queueIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'id',
				'Duplicate queue job id in the export.',
				null,
				$id,
			);
		}

		if ($type !== JobType::FEDERATION_DELIVER) {
			$violations[] = new MigrationViolation(
				$record,
				'type',
				'Queue job type is not handled by the core.',
				JobType::FEDERATION_DELIVER,
				$type,
			);
		}

		$this->requireHttpUrl($record, 'inbox', $inbox, $violations);
		$this->requireHttpUrl($record, 'key_id', $keyId, $violations);

		if (!in_array($keyId, $accountKeys, true)) {
			$violations[] = new MigrationViolation(
				$record,
				'key_id',
				'Queue job points at a key id that is not in the export.',
				'an exported account key_id',
				$keyId,
			);
		}

		$this->timestamp($availableValue, $record, 'available_at', $violations);
		if ($deadValue !== null) {
			$this->timestamp($deadValue, $record, 'dead_at', $violations);

			if ($warnOnDeadJob) {
				$violations[] = new MigrationViolation(
					$record,
					'dead_at',
					'The job is dead-lettered; the cutover will not retry it and the peer never received it.',
					'a live job, or an explicit decision to abandon this delivery',
					$deadValue,
					MigrationViolation::WARN,
				);
			}
		}

		if ($attempts < 0) {
			$violations[] = new MigrationViolation(
				$record,
				'attempts',
				'Queue attempts cannot be negative.',
				0,
				$attempts,
			);
		}

		$queueIds[$id] = true;

		if (!in_array($activityType, self::ACTIVITY_TYPES, true)) {
			$violations[] = new MigrationViolation(
				$record,
				'activity_type',
				'Queue job has an unsupported activity type.',
				implode(', ', self::ACTIVITY_TYPES),
				$activityType,
			);

			return;
		}

		$knownIds = match ($activityType) {
			'Create' => $createIds,
			'Delete' => $deleteIds,
			'Follow' => $followIds,
			'Undo' => $undoIds,
			'Accept' => null,
		};

		if ($knownIds !== null && !array_key_exists($activityId, $knownIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'activity_id',
				'Queue job activity id does not belong to an exported post or following edge.',
				'a known ' . $activityType . ' activity id',
				$activityId,
			);
		}
	}

	/**
	 * @param array<string, mixed>     $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true>      $activityIds
	 */
	private function validateActivityLog(
		array $row,
		string $record,
		array &$violations,
		array &$activityIds,
	): void {
		$activityId = $this->requiredString($row, 'activity_id', $record);
		$seenValue = $this->requiredString($row, 'seen_at', $record);

		$this->checkLength($record, 'activity_id', $activityId, 512, $violations);

		if (array_key_exists($activityId, $activityIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'activity_id',
				'Duplicate activity log id in the export.',
				null,
				$activityId,
			);
		}

		$this->timestamp($seenValue, $record, 'seen_at', $violations);
		$activityIds[$activityId] = true;
	}

	/**
	 * The optional version-2 sections.
	 *
	 * They are optional so a host can adopt them per feature; whatever is
	 * present is cross-checked, and a declared count is only compared when the
	 * matching section exists. Nothing here changes a version-1 result.
	 *
	 * @param array<string, mixed> $dataset
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true> $postIds
	 * @param array<string, array{record: string, revision: ?int, attachment_ids: list<string>}> $postMeta
	 * @param array<string, int> $baseCounts
	 */
	private function validateVersionTwo(
		array $dataset,
		array &$violations,
		array $postIds,
		array $postMeta,
		array $baseCounts,
	): void {
		/** @var array<string, array<int, string>> $revisionsByPost */
		$revisionsByPost = [];
		$revisionRows = 0;
		foreach ($this->optionalRecords($dataset, 'revisions') as $index => $entry) {
			$revisionRows++;
			$this->validateRevision(
				$this->record($entry, 'revisions[' . $index . ']'),
				'revisions[' . $index . ']',
				$violations,
				$postIds,
				$revisionsByPost,
			);
		}

		/** @var array<string, string> $mediaIds */
		$mediaIds = [];
		$mediaRows = 0;
		foreach ($this->optionalRecords($dataset, 'media') as $index => $entry) {
			$mediaRows++;
			$this->validateMedia(
				$this->record($entry, 'media[' . $index . ']'),
				'media[' . $index . ']',
				$violations,
				$postIds,
				$mediaIds,
			);
		}

		/** @var array<string, true> $reactionIds */
		$reactionIds = [];
		/** @var array<string, true> $reactionKeys */
		$reactionKeys = [];
		$reactionRows = 0;
		foreach ($this->optionalRecords($dataset, 'reactions') as $index => $entry) {
			$reactionRows++;
			$this->validateReaction(
				$this->record($entry, 'reactions[' . $index . ']'),
				'reactions[' . $index . ']',
				$violations,
				$reactionIds,
				$reactionKeys,
			);
		}

		/** @var array<string, true> $quotedPosts */
		$quotedPosts = [];
		$quoteRows = 0;
		foreach ($this->optionalRecords($dataset, 'quotes') as $index => $entry) {
			$quoteRows++;
			$this->validateQuote(
				$this->record($entry, 'quotes[' . $index . ']'),
				'quotes[' . $index . ']',
				$violations,
				$postIds,
				$quotedPosts,
			);
		}

		$this->validateRevisionBaselines($postMeta, $revisionsByPost, $violations);
		$this->validateMediaBaselines($postMeta, $mediaIds, $violations);

		$this->validateCounts($dataset, $violations, $baseCounts + [
			'revisions' => $revisionRows,
			'media' => $mediaRows,
			'reactions' => $reactionRows,
			'quotes' => $quoteRows,
		]);
	}

	/**
	 * The live revision of a post, when the export declares one.
	 *
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 */
	private function postRevision(array $row, string $record, array &$violations): ?int
	{
		if (!array_key_exists('revision', $row) || $row['revision'] === null) {
			return null;
		}

		$value = $row['revision'];
		if (!is_int($value) || $value < 1) {
			$violations[] = new MigrationViolation(
				$record,
				'revision',
				'A live revision must be an integer starting at one.',
				'>= 1',
				$value,
			);

			return null;
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 *
	 * @return list<string>
	 */
	private function attachmentIds(array $row, string $record, array &$violations): array
	{
		if (!array_key_exists('attachment_ids', $row) || $row['attachment_ids'] === null) {
			return [];
		}

		$value = $row['attachment_ids'];
		if (!is_array($value) || !array_is_list($value)) {
			$violations[] = new MigrationViolation(
				$record,
				'attachment_ids',
				'Attachment ids must be a JSON array of strings.',
				'list of attachment ids',
				$value,
			);

			return [];
		}

		$ids = [];
		foreach ($value as $id) {
			if (!is_string($id) || trim($id) === '') {
				$violations[] = new MigrationViolation(
					$record,
					'attachment_ids',
					'Attachment ids must be non-empty strings.',
					'non-empty string',
					$id,
				);
				continue;
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true> $postIds
	 * @param array<string, array<int, string>> $revisionsByPost
	 */
	private function validateRevision(
		array $row,
		string $record,
		array &$violations,
		array $postIds,
		array &$revisionsByPost,
	): void {
		$postId = $this->requiredString($row, 'post_id', $record);
		$revision = $this->requiredInt($row, 'revision', $record);
		$content = $this->stringValue($row, 'content', $record);
		$warning = $this->nullableString($row, 'content_warning', $record);
		$editedValue = $this->requiredString($row, 'edited_at', $record);

		if (!array_key_exists($postId, $postIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'post_id',
				'Revision points at a post that is not in the export.',
				'a known post id',
				$postId,
			);
		}

		if ($revision < 1) {
			$violations[] = new MigrationViolation(
				$record,
				'revision',
				'A stored revision number starts at one.',
				'>= 1',
				$revision,
			);
		} elseif (array_key_exists($revision, $revisionsByPost[$postId] ?? [])) {
			$violations[] = new MigrationViolation(
				$record,
				'revision',
				'Duplicate revision number for this post.',
				'a revision number that is not stored yet',
				$revision,
			);
		} else {
			$revisionsByPost[$postId][$revision] = trim($editedValue);
		}

		if (trim($content) === '') {
			$violations[] = new MigrationViolation(
				$record,
				'content',
				'A superseded version with empty content could not have been created.',
				'non-empty content',
				$content,
			);
		}

		if ($warning !== null && mb_strlen($warning) > 200) {
			$violations[] = new MigrationViolation(
				$record,
				'content_warning',
				'A content warning may be at most 200 characters long.',
				'<= 200 characters',
				mb_strlen($warning) . ' characters',
			);
		}

		$this->timestamp($editedValue, $record, 'edited_at', $violations);
	}

	/**
	 * A post's live revision and its stored history must agree, or the edit
	 * history is silently incomplete after the cutover.
	 *
	 * @param array<string, array{record: string, revision: ?int, attachment_ids: list<string>}> $postMeta
	 * @param array<string, array<int, string>> $revisionsByPost
	 * @param list<MigrationViolation> $violations
	 */
	private function validateRevisionBaselines(array $postMeta, array $revisionsByPost, array &$violations): void
	{
		foreach ($postMeta as $postId => $meta) {
			$versions = $revisionsByPost[$postId] ?? [];
			ksort($versions);

			$expected = $meta['revision'] === null ? count($versions) : max(0, $meta['revision'] - 1);
			if (count($versions) !== $expected) {
				$violations[] = new MigrationViolation(
					$meta['record'],
					'revision',
					'The exported revision history does not cover every replaced version.',
					$expected . ' stored version(s)',
					count($versions) . ' exported version(s)',
				);

				continue;
			}

			$position = 1;
			$previous = null;
			foreach ($versions as $number => $editedAt) {
				if ($number !== $position) {
					$violations[] = new MigrationViolation(
						$meta['record'],
						'revision',
						'Revision numbers must be contiguous, starting at one.',
						'contiguous numbering',
						'gap before ' . $number,
					);

					break;
				}

				if ($previous !== null && $editedAt < $previous) {
					$violations[] = new MigrationViolation(
						$meta['record'],
						'edited_at',
						'Revision timestamps must not go backwards.',
						'a later or equal timestamp',
						$editedAt,
					);
				}

				$previous = $editedAt;
				$position++;
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true> $postIds
	 * @param array<string, string> $mediaIds
	 */
	private function validateMedia(
		array $row,
		string $record,
		array &$violations,
		array $postIds,
		array &$mediaIds,
	): void {
		$attachmentId = $this->requiredString($row, 'attachment_id', $record);
		$postId = $this->requiredString($row, 'post_id', $record);
		$storageKey = $this->requiredString($row, 'storage_key', $record);
		$mimeType = $this->requiredString($row, 'mime_type', $record);
		$byteSize = $this->requiredInt($row, 'byte_size', $record);
		$checksum = $this->nullableString($row, 'checksum_sha256', $record);

		$this->checkLength($record, 'attachment_id', $attachmentId, 128, $violations);
		$this->checkLength($record, 'storage_key', $storageKey, 512, $violations);

		if (!array_key_exists($postId, $postIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'post_id',
				'Media points at a post that is not in the export.',
				'a known post id',
				$postId,
			);
		}

		if (array_key_exists($attachmentId, $mediaIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'attachment_id',
				'Duplicate media attachment id in the export.',
				'unique attachment id',
				$attachmentId,
			);
		}

		if (!in_array($mimeType, Attachment::ALLOWED_MIME_TYPES, true)) {
			$violations[] = new MigrationViolation(
				$record,
				'mime_type',
				'Media type is not one the core serves.',
				implode(', ', Attachment::ALLOWED_MIME_TYPES),
				$mimeType,
			);
		}

		if ($byteSize < 1) {
			$violations[] = new MigrationViolation(
				$record,
				'byte_size',
				'A stored media object is never empty.',
				'> 0',
				$byteSize,
			);
		}

		if ($checksum === null) {
			$violations[] = new MigrationViolation(
				$record,
				'checksum_sha256',
				'Media has no checksum; the cutover cannot verify the bytes it copies.',
				'sha256 of the original bytes',
				null,
				MigrationViolation::WARN,
			);
		} elseif (preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) {
			$violations[] = new MigrationViolation(
				$record,
				'checksum_sha256',
				'Expected a lower-case hexadecimal sha256 checksum.',
				'64 hex characters',
				$checksum,
			);
		}

		$mediaIds[$attachmentId] = $postId;
	}

	/**
	 * A linked attachment that is missing from the media rows would render a
	 * broken post after the cutover, so it fails instead of disappearing.
	 *
	 * @param array<string, array{record: string, revision: ?int, attachment_ids: list<string>}> $postMeta
	 * @param array<string, string> $mediaIds
	 * @param list<MigrationViolation> $violations
	 */
	private function validateMediaBaselines(array $postMeta, array $mediaIds, array &$violations): void
	{
		foreach ($postMeta as $meta) {
			foreach ($meta['attachment_ids'] as $attachmentId) {
				if (!array_key_exists($attachmentId, $mediaIds)) {
					$violations[] = new MigrationViolation(
						$meta['record'],
						'attachment_ids',
						'The post links an attachment that has no media row in the export.',
						'an exported media row',
						$attachmentId,
					);
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true> $reactionIds
	 * @param array<string, true> $reactionKeys
	 */
	private function validateReaction(
		array $row,
		string $record,
		array &$violations,
		array &$reactionIds,
		array &$reactionKeys,
	): void {
		$activityId = $this->requiredString($row, 'activity_id', $record);
		$actorId = $this->requiredString($row, 'actor_id', $record);
		$objectId = $this->requiredString($row, 'object_id', $record);
		$kind = $this->requiredString($row, 'kind', $record);
		$emoji = $this->nullableString($row, 'emoji', $record);
		$createdValue = $this->requiredString($row, 'created_at', $record);

		$this->checkLength($record, 'activity_id', $activityId, 512, $violations);
		$this->checkLength($record, 'object_id', $objectId, 1024, $violations);
		$this->requireHttpUrl($record, 'actor_id', $actorId, $violations);
		$this->requireHttpUrl($record, 'object_id', $objectId, $violations);

		if (!in_array($kind, ['like', 'emoji'], true)) {
			$violations[] = new MigrationViolation(
				$record,
				'kind',
				'Unsupported reaction kind.',
				'like or emoji',
				$kind,
			);
		}

		if ($kind === 'emoji') {
			if ($emoji === null || trim($emoji) === '') {
				$violations[] = new MigrationViolation(
					$record,
					'emoji',
					'An emoji reaction needs its emoji.',
					'a non-empty emoji',
					null,
				);
			} elseif (mb_strlen($emoji) > 64) {
				$violations[] = new MigrationViolation(
					$record,
					'emoji',
					'An emoji may be at most 64 characters long.',
					'<= 64 characters',
					mb_strlen($emoji) . ' characters',
				);
			}
		} elseif ($emoji !== null) {
			$violations[] = new MigrationViolation(
				$record,
				'emoji',
				'A like carries an emoji; the export would turn it into an emoji reaction.',
				'no emoji on a like',
				$emoji,
				MigrationViolation::WARN,
			);
		}

		$this->timestamp($createdValue, $record, 'created_at', $violations);

		if (array_key_exists($activityId, $reactionIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'activity_id',
				'Duplicate reaction activity id in the export.',
				'unique activity id',
				$activityId,
			);
		}

		$key = $actorId . "\0" . $objectId . "\0" . $kind . ':' . ($emoji ?? '');
		if (array_key_exists($key, $reactionKeys)) {
			$violations[] = new MigrationViolation(
				$record,
				'actor_id',
				'The same actor already reacted to this object with the same reaction.',
				'one reaction per actor, object and emoji',
				$actorId . ' -> ' . $objectId,
			);
		}

		$reactionIds[$activityId] = true;
		$reactionKeys[$key] = true;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<MigrationViolation> $violations
	 * @param array<string, true> $postIds
	 * @param array<string, true> $quotedPosts
	 */
	private function validateQuote(
		array $row,
		string $record,
		array &$violations,
		array $postIds,
		array &$quotedPosts,
	): void {
		$postId = $this->requiredString($row, 'post_id', $record);
		$quotedUrl = $this->requiredString($row, 'quoted_url', $record);
		$authorization = $this->nullableString($row, 'authorization', $record);

		$this->checkLength($record, 'quoted_url', $quotedUrl, 1024, $violations);
		$this->requireHttpUrl($record, 'quoted_url', $quotedUrl, $violations);

		if (!array_key_exists($postId, $postIds)) {
			$violations[] = new MigrationViolation(
				$record,
				'post_id',
				'Quote points at a post that is not in the export.',
				'a known post id',
				$postId,
			);
		}

		if (array_key_exists($postId, $quotedPosts)) {
			$violations[] = new MigrationViolation(
				$record,
				'post_id',
				'A post can quote at most one object.',
				'one quote per post',
				$postId,
			);
		}

		if ($authorization !== null) {
			$this->checkLength($record, 'authorization', $authorization, 512, $violations);
		}

		$quotedPosts[$postId] = true;
	}

	/**
	 * @param array<string, mixed> $dataset
	 * @param list<MigrationViolation> $violations
	 * @param array<string, int> $actual
	 */
	private function validateCounts(array $dataset, array &$violations, array $actual): void
	{
		if (!array_key_exists('counts', $dataset)) {
			return;
		}

		$counts = $this->record($dataset['counts'], 'counts');
		foreach ($counts as $feature => $value) {
			if (!is_string($feature)) {
				throw new InvalidMigrationDatasetException('dataset.counts: expected feature names as keys.');
			}

			if (!is_int($value)) {
				$violations[] = new MigrationViolation(
					'counts',
					$feature,
					'A declared feature count must be an integer.',
					'integer',
					$value,
				);
				continue;
			}

			if (!array_key_exists($feature, $actual)) {
				$violations[] = new MigrationViolation(
					'counts',
					$feature,
					'The declared count has no exported section to compare with.',
					'a section with one of: ' . implode(', ', array_keys($actual)),
					$feature,
					MigrationViolation::WARN,
				);
				continue;
			}

			if ($value !== $actual[$feature]) {
				$violations[] = new MigrationViolation(
					'counts',
					$feature,
					'The declared count does not match the exported rows.',
					$actual[$feature],
					$value,
				);
			}
		}
	}

	/**
	 * @param array<string, mixed> $dataset
	 *
	 * @return list<mixed>
	 */
	private function optionalRecords(array $dataset, string $field): array
	{
		if (!array_key_exists($field, $dataset)) {
			return [];
		}

		return $this->records($dataset, $field);
	}

	/**
	 * @param array<string, mixed>     $row
	 * @param list<MigrationViolation> $violations
	 */
	private function timestamp(
		string $value,
		string $record,
		string $field,
		array &$violations,
	): ?DateTimeImmutable {
		try {
			return Time::parse($value);
		} catch (InvalidArgumentException) {
			$violations[] = new MigrationViolation(
				$record,
				$field,
				'Not a canonical UTC timestamp.',
				Time::STORAGE_FORMAT,
				$value,
			);

			return null;
		}
	}

	/**
	 * @param list<MigrationViolation> $violations
	 */
	private function requireHttpUrl(string $record, string $field, string $value, array &$violations): void
	{
		if (!$this->isHttpUrl($value)) {
			$violations[] = new MigrationViolation(
				$record,
				$field,
				'Expected an absolute http(s) URL.',
				'http(s) URL',
				$value,
			);
		}
	}

	private function isHttpUrl(string $value): bool
	{
		$parts = parse_url($value);
		$scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
		$host = is_array($parts) ? ($parts['host'] ?? null) : null;

		return is_string($scheme)
			&& in_array($scheme, ['http', 'https'], true)
			&& is_string($host)
			&& $host !== '';
	}

	/**
	 * @param list<MigrationViolation> $violations
	 */
	private function checkLength(string $record, string $field, string $value, int $max, array &$violations): void
	{
		if (mb_strlen($value) > $max) {
			$violations[] = new MigrationViolation(
				$record,
				$field,
				'Value is longer than the target column allows.',
				'<= ' . $max . ' characters',
				mb_strlen($value) . ' characters',
			);
		}
	}

	/**
	 * @param list<MigrationViolation> $violations
	 */
	private function compare(
		string $record,
		string $field,
		string $message,
		mixed $expected,
		mixed $actual,
		array &$violations,
	): void {
		if ($expected === $actual) {
			return;
		}

		$violations[] = new MigrationViolation($record, $field, $message, $expected, $actual);
	}

	/**
	 * @param array<string, mixed> $dataset
	 *
	 * @return list<mixed>
	 */
	private function records(array $dataset, string $field): array
	{
		if (!array_key_exists($field, $dataset) || !is_array($dataset[$field]) || !array_is_list($dataset[$field])) {
			throw new InvalidMigrationDatasetException('dataset.' . $field . ': expected a JSON array.');
		}

		return $dataset[$field];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(mixed $entry, string $record): array
	{
		if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
			throw new InvalidMigrationDatasetException($record . ': expected a JSON object.');
		}

		return $entry;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function requiredString(array $row, string $field, string $record): string
	{
		if (!array_key_exists($field, $row) || !is_string($row[$field]) || trim($row[$field]) === '') {
			throw new InvalidMigrationDatasetException($record . '.' . $field . ': expected a non-empty string.');
		}

		return $row[$field];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function stringValue(array $row, string $field, string $record): string
	{
		if (!array_key_exists($field, $row) || !is_string($row[$field])) {
			throw new InvalidMigrationDatasetException($record . '.' . $field . ': expected a string.');
		}

		return $row[$field];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function nullableString(array $row, string $field, string $record): ?string
	{
		if (!array_key_exists($field, $row) || $row[$field] === null) {
			return null;
		}

		if (!is_string($row[$field])) {
			throw new InvalidMigrationDatasetException($record . '.' . $field . ': expected a string or null.');
		}

		return $row[$field];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function requiredInt(array $row, string $field, string $record): int
	{
		if (!array_key_exists($field, $row) || !is_int($row[$field])) {
			throw new InvalidMigrationDatasetException($record . '.' . $field . ': expected an integer.');
		}

		return $row[$field];
	}
}
