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
use AlohaSocial\Core\Domain\Federation\FollowPolicy;
use AlohaSocial\Core\Domain\Federation\FollowState;
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
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\Time;
use Throwable;

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
 * inbox. An Accept/Reject updates an outgoing pending follow only when the
 * signing actor is its target and the embedded Follow id matches. An
 * Undo(Follow) removes the edge. Create, Delete and Announce are accepted
 * only from an actor followed by at least one local account; they change the
 * remote-post store, which the home timeline reads.
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
		private readonly RemotePostFactory $remotePostFactory,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
		private readonly ?BlockedActorPolicy $blockedActors = null,
		private readonly ?FollowPolicy $followPolicy = null,
		private readonly ?RemoteMediaService $remoteMedia = null,
		private readonly ?NotificationService $notifications = null,
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

		$claimed = $this->replay->claim($activityId, $this->clock->now());
		if (!$claimed) {
			// A duplicate Accept/Reject is a retry of the same response. It
			// must not be treated as a new state transition, but it is safe
			// to re-apply because the repository only moves a pending row.
			if ($type === 'Accept' || $type === 'Reject') {
				$this->handleFollowResponse(
					$actor,
					$activity,
					$type === 'Accept' ? FollowState::Accepted : FollowState::Rejected,
				);

				return;
			}

			throw new InboxRejectedException(
				'replayed_activity',
				'This activity has already been accepted.',
			);
		}

		try {
			match ($type) {
				'Follow' => $this->handleFollow($actor, $activity),
				'Accept' => $this->handleFollowResponse($actor, $activity, FollowState::Accepted),
				'Reject' => $this->handleFollowResponse($actor, $activity, FollowState::Rejected),
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

		$post = $this->remotePostFactory->fromCreate($actor->actorId, $activity);
		$this->insertRemotePost($post);

		// Import after the post is durable. The importer isolates every
		// attachment, so a slow or broken image never discards the post.
		$this->remoteMedia?->import($post, $activity['object'] ?? null);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleAnnounce(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$objectId = $this->remotePostFactory->targetId($activity['object'] ?? null, 'Announce');
		$target = $this->remotePosts->findPostByObjectId($objectId);

		$post = $this->remotePostFactory->fromAnnounce($actor->actorId, $activity, $target);
		$this->insertRemotePost($post);

		$this->remoteMedia?->import($post, $activity['object'] ?? null);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleDelete(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$targetId = $this->remotePostFactory->targetId($activity['object'] ?? null, 'Delete');

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
	 * Applies a signed Accept or Reject to an outgoing pending Follow.
	 *
	 * The signer must be the actor the local account followed. When the
	 * original Follow is embedded, its actor and object are checked against
	 * the stored row as well. An unknown or already removed Follow id is a
	 * successful no-op: an Unfollow may have raced the response, and a late
	 * duplicate must not recreate the edge.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function handleFollowResponse(RemoteActor $actor, array $activity, FollowState $state): void
	{
		$object = $activity['object'] ?? null;
		$follow = null;

		if (is_string($object)) {
			$followActivityId = trim($object);
		} elseif (is_array($object)) {
			if (($object['type'] ?? null) !== 'Follow') {
				throw new InboxRejectedException('invalid_activity', 'An Accept/Reject needs an embedded Follow or its id.');
			}

			$follow = $object;
			$id = $object['id'] ?? null;
			if (!is_string($id) || trim($id) === '') {
				throw new InboxRejectedException('invalid_activity', 'An embedded Follow needs an id.');
			}

			$followActivityId = trim($id);
		} else {
			throw new InboxRejectedException('invalid_activity', 'An Accept/Reject needs an embedded Follow or its id.');
		}

		if ($followActivityId === '') {
			throw new InboxRejectedException('invalid_activity', 'An Accept/Reject needs a Follow id.');
		}

		$stored = $this->following->findByActivityId($followActivityId);
		if ($stored === null) {
			return;
		}

		if ($stored->actorId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'Only the followed actor may accept or reject this Follow.',
			);
		}

		if ($follow !== null) {
			$followActor = $follow['actor'] ?? null;
			$followObject = $follow['object'] ?? null;

			if (
				!is_string($followActor)
				|| $followActor !== $this->activities->actorId($stored->localUserId)
				|| !is_string($followObject)
				|| $followObject !== $stored->actorId
			) {
				throw new InboxRejectedException(
					'actor_mismatch',
					'The embedded Follow does not belong to this outgoing request.',
				);
			}
		}

		$this->following->respondToFollow($followActivityId, $actor->actorId, $state);
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

		if ($this->followPolicy?->accepts($actor, $localUserId) === false) {
			throw new InboxRejectedException(
				'follow_not_accepted',
				'This account does not accept the Follow.',
			);
		}

		$result = $this->followers->follow(new FollowActor(
			$localUserId,
			$actor->actorId,
			$actor->inboxUrl,
			$actor->sharedInboxUrl,
		));

		// Record on every accepted Follow, not only the first edge: the
		// deterministic id makes the write idempotent, and a retry after a
		// notification-storage failure can heal the missing notification.
		$this->notifications?->recordFollow($localUserId, $actor->actorId);

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

		if (is_string($object)) {
			// Some peers send only the Announce id. It is safe to look up the
			// stored row by that id, but the ownership check still happens in
			// undoAnnounceById().
			$this->undoAnnounceById($actor, $object);

			return;
		}

		$type = is_array($object) ? ($object['type'] ?? null) : null;

		if ($type === 'Follow') {
			$this->undoFollow($actor, $object);

			return;
		}

		if ($type === 'Announce') {
			$this->undoAnnounce($actor, $object);

			return;
		}

		throw new InboxRejectedException(
			'unsupported_activity',
			'Only Undo(Follow) and Undo(Announce) are supported.',
		);
	}

	/**
	 * @param array<string, mixed> $follow
	 */
	private function undoFollow(RemoteActor $actor, array $follow): void
	{
		$undoneActor = $follow['actor'] ?? null;
		if (!is_string($undoneActor) || $undoneActor !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only undo its own Follow.',
			);
		}

		$target = $follow['object'] ?? null;
		if (!is_string($target)) {
			throw new InboxRejectedException('invalid_activity', 'A Follow needs an object actor.');
		}

		$localUserId = $this->localUserFromActorId($target);

		// An Undo for a follow that is already gone is still a successful
		// Undo: the peer's intent is satisfied.
		$this->followers->unfollowByActor($localUserId, $actor->actorId);
	}

	/**
	 * @param array<string, mixed> $announce
	 */
	private function undoAnnounce(RemoteActor $actor, array $announce): void
	{
		$this->requireFollowedContent($actor);

		$actorId = $announce['actor'] ?? null;
		if (!is_string($actorId) || $actorId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only undo its own Announce.',
			);
		}

		$activityId = $announce['id'] ?? null;
		if (!is_string($activityId) || trim($activityId) === '') {
			throw new InboxRejectedException('invalid_activity', 'An Announce needs an activity id.');
		}

		$this->undoAnnounceById($actor, $activityId);
	}

	private function undoAnnounceById(RemoteActor $actor, string $activityId): void
	{
		$this->requireFollowedContent($actor);

		$activityId = trim($activityId);
		if ($activityId === '') {
			throw new InboxRejectedException('invalid_activity', 'An Undo needs an Announce id.');
		}

		$stored = $this->remotePosts->findByActivityId($activityId);
		if ($stored === null) {
			// The peer's intended state (no boost) is already true. This also
			// makes a retried Undo after a lost response a successful no-op.
			return;
		}

		if ($stored->kind !== RemotePostKind::Boost) {
			// The id names a post, not an Announce. Never let an Undo(Announce)
			// remove an unrelated stored object.
			return;
		}

		if ($stored->actorId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only undo its own Announce.',
			);
		}

		$this->remotePosts->softDeleteByActivityId(
			$activityId,
			$actor->actorId,
			Time::toSecondPrecision($this->clock->now()),
		);
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
