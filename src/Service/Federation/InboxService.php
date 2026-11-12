<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\SignatureException;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;
use Throwable;

use function is_array;
use function is_string;

/**
 * Accepts one signed ActivityPub activity from a remote server.
 *
 * The order is deliberate:
 *
 * 1. the request must be signed;
 * 2. the key id must resolve to an actor;
 * 3. the signature must verify over the raw body and the signed headers;
 * 4. the activity actor must be the actor that owns the key;
 * 5. the host's optional policy must allow the actor;
 * 6. the activity id must not have been accepted before;
 * 7. content types require a local follower; only then does anything change.
 *
 * A Follow creates the follower edge and queues an Accept for the follower's
 * inbox. An Undo(Follow) removes the edge. Create, Delete and Announce are
 * accepted only from an actor followed by at least one local account; they
 * change the remote-post store, which the home timeline reads.
 *
 * Every refusal is an {@see InboxRejectedException} with a stable reason; this
 * class knows nothing about HTTP status codes.
 */
final class InboxService
{
	/** The clock window a signed request may be off by. */
	public const MAX_SKEW_SECONDS = 300;

	public function __construct(
		private readonly ActorResolver $actors,
		private readonly FollowerService $followers,
		private readonly FollowingService $following,
		private readonly RemotePostRepository $remotePosts,
		private readonly ActivityLog $replay,
		private readonly ActivityFactory $activities,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
		private readonly ?BlockedActorPolicy $blockedActors = null,
	) {
	}

	/**
	 * @param array<string, string> $headers lower-case header names
	 * @param array<string, mixed>  $activity the already decoded JSON object
	 *
	 * @throws InboxRejectedException  when the request must be refused
	 * @throws \AlohaSocial\Core\Exceptions\ConfigurationException when the instance URL is missing
	 */
	public function receive(string $body, array $headers, string $path, array $activity): void
	{
		$actor = $this->verifySignature($body, $headers, $path);

		$activityId = $activity['id'] ?? null;
		$type = $activity['type'] ?? null;
		$actorId = $activity['actor'] ?? null;

		if (!is_string($activityId) || trim($activityId) === '' || !is_string($type) || !is_string($actorId)) {
			throw new InboxRejectedException(
				'invalid_activity',
				'An activity needs a string "id", "type" and "actor".',
			);
		}

		$activityId = trim($activityId);
		if (mb_strlen($activityId) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The activity id is too long.');
		}

		$activity['id'] = $activityId;

		if ($actorId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'The activity actor does not own the signing key.',
			);
		}

		if ($this->blockedActors?->isBlocked($actor->actorId) === true) {
			throw new InboxRejectedException(
				'blocked_actor',
				'This actor is blocked on this instance.',
			);
		}

		if (!$this->replay->claim($activityId, $this->clock->now())) {
			throw new InboxRejectedException(
				'replayed_activity',
				'This activity has already been accepted.',
			);
		}

		try {
			match ($type) {
				'Follow' => $this->handleFollow($actor, $activity),
				'Undo' => $this->handleUndo($actor, $activity),
				'Create' => $this->handleCreate($actor, $activity),
				'Delete' => $this->handleDelete($actor, $activity),
				'Announce' => $this->handleAnnounce($actor, $activity),
				default => throw new InboxRejectedException(
					'unsupported_activity',
					'Unsupported activity type "' . $type . '".',
				),
			};
		} catch (Throwable $exception) {
			// A bad request must not burn the activity id: the peer may fix
			// its document and deliver the very same activity again.
			$this->replay->release($activityId);

			throw $exception;
		}
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function verifySignature(string $body, array $headers, string $path): RemoteActor
	{
		$signature = $headers['signature'] ?? null;
		if (!is_string($signature) || trim($signature) === '') {
			throw new InboxRejectedException('signature_required', 'The request is not signed.');
		}

		try {
			$parameters = HttpSignature::parseSignatureHeader($signature);
		} catch (SignatureException) {
			throw new InboxRejectedException('invalid_signature', 'The Signature header is malformed.');
		}

		$keyId = $parameters['keyid'] ?? null;
		if (!is_string($keyId) || trim($keyId) === '') {
			throw new InboxRejectedException('invalid_signature', 'The signature does not name a key id.');
		}

		try {
			$actor = $this->actors->resolveByKeyId($keyId);
		} catch (UnknownActorException) {
			throw new InboxRejectedException('unknown_actor', 'The signing actor could not be resolved.');
		}

		try {
			HttpSignature::verify(
				$actor->publicKeyPem,
				'POST',
				$path,
				$headers,
				$body,
				self::MAX_SKEW_SECONDS,
				$this->clock->now(),
			);
		} catch (SignatureException $exception) {
			$reason = $exception->reason === 'stale_date' ? 'signature_expired' : 'invalid_signature';

			throw new InboxRejectedException($reason, 'The signature could not be verified.');
		}

		return $actor;
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleCreate(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$object = $activity['object'] ?? null;
		if (!is_array($object)) {
			throw new InboxRejectedException('invalid_activity', 'A Create needs an embedded object.');
		}

		$objectId = $this->objectId($object, 'Create');
		$objectActor = $object['attributedTo'] ?? $actor->actorId;
		if (!is_string($objectActor) || $objectActor !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'The created object belongs to another actor.',
			);
		}

		$content = $object['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			throw new InboxRejectedException('invalid_activity', 'A created Note needs string content.');
		}

		$this->insertRemotePost(new RemotePost(
			activityId: (string) $activity['id'],
			objectId: $objectId,
			actorId: $actor->actorId,
			kind: RemotePostKind::Post,
			content: $content,
			contentWarning: $this->contentWarning($object),
			publishedAt: $this->publishedAt($object['published'] ?? null, $activity['published'] ?? null),
			objectAuthorId: $this->objectAuthor($object, $actor->actorId),
		));
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleAnnounce(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$object = $activity['object'] ?? null;
		$objectId = $this->targetId($object, 'Announce');
		$target = $this->remotePosts->findPostByObjectId($objectId);

		$embedded = is_array($object) ? $object : [];
		$content = $this->optionalContent($embedded);
		$contentWarning = $this->contentWarning($embedded);

		$this->insertRemotePost(new RemotePost(
			activityId: (string) $activity['id'],
			objectId: $objectId,
			actorId: $actor->actorId,
			kind: RemotePostKind::Boost,
			content: $content ?? $target?->content ?? '',
			contentWarning: $contentWarning ?? $target?->contentWarning,
			publishedAt: $this->publishedAt(
				$activity['published'] ?? null,
				$embedded['published'] ?? null,
				$target === null ? null : Time::format($target->publishedAt),
			),
			objectAuthorId: $this->announcedObjectAuthor($embedded) ?? $target?->objectAuthorId,
		));
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleDelete(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$targetId = $this->targetId($activity['object'] ?? null, 'Delete');

		// A peer can delete either a Create/Announce by activity id or the
		// Note itself. The actor must own whichever row matches.
		$byActivity = $this->remotePosts->findByActivityId($targetId);
		if ($byActivity !== null) {
			if ($byActivity->actorId !== $actor->actorId) {
				throw new InboxRejectedException(
					'actor_mismatch',
					'An actor may only delete its own activity.',
				);
			}

			$this->remotePosts->softDeleteByActivityId(
				$targetId,
				$actor->actorId,
				Time::toSecondPrecision($this->clock->now()),
			);

			return;
		}

		$post = $this->remotePosts->findPostByObjectId($targetId);
		if ($post === null) {
			// A Delete for something not stored is already satisfied; peers
			// retry deliveries, and treating that as an error would loop.
			return;
		}

		if ($post->actorId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only delete its own post.',
			);
		}

		$this->remotePosts->softDeletePostByObjectId(
			$targetId,
			$actor->actorId,
			Time::toSecondPrecision($this->clock->now()),
		);
	}

	private function requireFollowedContent(RemoteActor $actor): void
	{
		if ($this->following->localUserIdsForActor($actor->actorId) === []) {
			throw new InboxRejectedException(
				'not_followed',
				'This actor is not followed by a local account.',
			);
		}
	}

	private function insertRemotePost(RemotePost $post): void
	{
		try {
			$this->remotePosts->insert($post);
		} catch (DatabaseException $exception) {
			if ($this->remotePosts->findByActivityId($post->activityId) !== null) {
				throw new InboxRejectedException(
					'replayed_activity',
					'This activity has already been accepted.',
				);
			}

			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function objectId(array $object, string $context): string
	{
		$id = $object['id'] ?? null;
		if (!is_string($id) || trim($id) === '') {
			throw new InboxRejectedException('invalid_activity', 'A ' . $context . ' object needs an id.');
		}

		$id = trim($id);
		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object id is too long.');
		}

		return $id;
	}

	private function targetId(mixed $object, string $context): string
	{
		if (is_string($object) && trim($object) !== '') {
			$id = trim($object);
		} elseif (is_array($object)) {
			$id = $this->objectId($object, $context);
		} else {
			throw new InboxRejectedException(
				'invalid_activity',
				'A ' . $context . ' needs an object id.',
			);
		}

		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object id is too long.');
		}

		return $id;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function contentWarning(array $object): ?string
	{
		$summary = $object['summary'] ?? null;
		if ($summary === null) {
			return null;
		}

		if (!is_string($summary)) {
			throw new InboxRejectedException('invalid_activity', 'A content warning must be a string.');
		}

		$summary = trim($summary);
		if ($summary === '') {
			return null;
		}

		if (mb_strlen($summary) > RemotePost::MAX_WARNING_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The content warning is too long.');
		}

		return $summary;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function optionalContent(array $object): ?string
	{
		$content = $object['content'] ?? null;
		if ($content === null) {
			return null;
		}

		if (!is_string($content)) {
			throw new InboxRejectedException('invalid_activity', 'Announced content must be a string.');
		}

		return $content;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function objectAuthor(array $object, string $fallback): string
	{
		$author = $object['attributedTo'] ?? $fallback;
		if (!is_string($author) || $author === '') {
			throw new InboxRejectedException('invalid_activity', 'The object author must be an actor id.');
		}

		if (mb_strlen($author) > RemotePost::MAX_ACTOR_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object author id is too long.');
		}

		return $author;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function announcedObjectAuthor(array $object): ?string
	{
		$author = $object['attributedTo'] ?? null;
		if ($author === null) {
			return null;
		}

		if (!is_string($author) || $author === '') {
			throw new InboxRejectedException('invalid_activity', 'The announced author must be an actor id.');
		}

		if (mb_strlen($author) > RemotePost::MAX_ACTOR_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The announced author id is too long.');
		}

		return $author;
	}

	private function publishedAt(mixed ...$values): DateTimeImmutable
	{
		foreach ($values as $value) {
			if ($value === null) {
				continue;
			}

			if (!is_string($value) || trim($value) === '') {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp must be a string.');
			}

			$value = trim($value);
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp is not RFC 3339.');
			}

			try {
				return Time::toSecondPrecision(new DateTimeImmutable($value));
			} catch (Exception) {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp is invalid.');
			}
		}

		return Time::toSecondPrecision($this->clock->now());
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleFollow(RemoteActor $actor, array $activity): void
	{
		$object = $activity['object'] ?? null;
		if (!is_string($object)) {
			throw new InboxRejectedException('invalid_activity', 'A Follow needs an object actor.');
		}

		$localUserId = $this->localUserFromActorId($object);

		$result = $this->followers->follow(new FollowActor(
			$localUserId,
			$actor->actorId,
			$actor->inboxUrl,
		));

		$accept = $this->activities->acceptFollow($localUserId, $result->follower->id, $activity);

		$this->queue->push(JobType::FEDERATION_DELIVER, [
			'inbox' => $actor->inboxUrl,
			'activity' => $accept,
			'key_id' => $this->activities->keyId($localUserId),
		]);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleUndo(RemoteActor $actor, array $activity): void
	{
		$object = $activity['object'] ?? null;

		if (!is_array($object) || ($object['type'] ?? null) !== 'Follow') {
			throw new InboxRejectedException(
				'unsupported_activity',
				'Only Undo(Follow) with an embedded Follow is supported.',
			);
		}

		$undoneActor = $object['actor'] ?? null;
		if (!is_string($undoneActor) || $undoneActor !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only undo its own Follow.',
			);
		}

		$target = $object['object'] ?? null;
		if (!is_string($target)) {
			throw new InboxRejectedException('invalid_activity', 'A Follow needs an object actor.');
		}

		$localUserId = $this->localUserFromActorId($target);

		// An Undo for a follow that is already gone is still a successful
		// Undo: the peer's intent is satisfied.
		$this->followers->unfollowByActor($localUserId, $actor->actorId);
	}

	/**
	 * Turns a local actor URL back into the account it belongs to.
	 *
	 * Only this instance's own actor URLs are accepted; anything else answers
	 * "unknown object" rather than revealing which other instances exist.
	 */
	private function localUserFromActorId(string $actorId): string
	{
		$prefix = $this->activities->baseUrl() . '/@';

		if (!str_starts_with($actorId, $prefix)) {
			throw new InboxRejectedException('unknown_object', 'The activity does not address a local actor.');
		}

		$encoded = substr($actorId, strlen($prefix));
		if ($encoded === '' || str_contains($encoded, '/')) {
			throw new InboxRejectedException('unknown_object', 'The activity does not address a local actor.');
		}

		$userId = rawurldecode($encoded);
		if (trim($userId) === '') {
			throw new InboxRejectedException('unknown_object', 'The activity does not address a local actor.');
		}

		return $userId;
	}
}
