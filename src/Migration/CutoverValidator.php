<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
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
use function mb_strlen;
use function parse_url;

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
 * The human-readable format is documented in
 * {@see docs/guides/migration-from-nextcloud.md#export-format}.
 */
final class CutoverValidator
{
	public const FORMAT = 'alohasocial.migration-cutover';

	public const VERSION = 1;

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

		if (($dataset['version'] ?? null) !== self::VERSION) {
			throw new InvalidMigrationDatasetException(
				'Unsupported version; expected ' . self::VERSION . '.',
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
		array $undoIds,
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
