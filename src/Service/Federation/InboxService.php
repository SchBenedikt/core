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
use AlohaSocial\Core\Domain\Post\QuoteState;
use AlohaSocial\Core\Service\QuoteService;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\SignatureException;
use AlohaSocial\Core\Service\AccountMoveException;
use AlohaSocial\Core\Service\AccountMoveService;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\Moderation\ModerationService;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\ReactionService;
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
 * Like, EmojiReact and their Undo are deliberately not gated on a follow
 * edge: a public post may be liked by any actor that can see it. The reaction
 * service applies the post's audience and the author's block list instead, and
 * keys the row by actor/object/kind/emoji so a retransmission with a new
 * activity id cannot be counted twice.
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
		private readonly ?LocalActorProfileProvider $profiles = null,
		private readonly ?RemoteMediaService $remoteMedia = null,
		private readonly ?NotificationService $notifications = null,
		private readonly ?PendingRemoteUpdateStore $pendingUpdates = null,
		private readonly ?ReactionService $reactions = null,
		private readonly ?RemotePollService $remotePolls = null,
		private readonly ?QuoteService $quotes = null,
		private readonly ?ModerationService $moderation = null,
		private readonly ?ModerationPolicy $moderationPolicy = null,
		private readonly ?AccountMoveService $accountMoves = null,
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

		if ($this->moderationPolicy?->allowsActor($actor->actorId) === false) {
			throw new InboxRejectedException(
				'moderation_blocked',
				'This actor is not allowed by the instance moderation policy.',
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

			// Reactions are keyed by identity, not by the activity id, so a
			// retransmitted Like/EmojiReact/Undo is already applied. Refusing it
			// would make a peer retry a delivery that can never change state.
			if ($type === 'Like' || $type === 'EmojiReact' || $type === 'Undo' || $type === 'Flag'
				|| $type === 'Move'
			) {
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
				'Like' => $this->requireReactions()->receiveLike($actor, $activity),
				'EmojiReact' => $this->requireReactions()->receiveEmojiReact($actor, $activity),
				'Accept' => $this->handleFollowOrQuoteResponse($actor, $activity, FollowState::Accepted),
				'Reject' => $this->handleFollowOrQuoteResponse($actor, $activity, FollowState::Rejected),
				'QuoteRequest' => $this->handleQuoteRequest($actor, $activity),
				'Move' => $this->handleMove($actor, $activity),
				'Undo' => $this->handleUndo($actor, $activity),
				'Create' => $this->handleCreate($actor, $activity),
				'Update' => $this->handleUpdate($actor, $activity),
				'Delete' => $this->handleDelete($actor, $activity),
				'Announce' => $this->handleAnnounce($actor, $activity),
				'Flag' => $this->handleFlag($actor, $activity),
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
	/**
	 * A signed Flag only files a report; it never changes publication state.
	 * The signer is the reporter, the object is a target URL and the content
	 * is bounded evidence. Evidence is not delivered anywhere else.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function handleFlag(RemoteActor $actor, array $activity): void
	{
		if ($this->moderation === null) {
			throw new InboxRejectedException(
				'moderation_not_configured',
				'This instance does not accept federated reports.',
			);
		}

		$content = $activity['content'] ?? null;
		$this->moderation->submitRemoteFlag(
			$actor->actorId,
			$activity['object'] ?? null,
			is_string($content) ? $content : null,
		);
	}

	private function handleCreate(RemoteActor $actor, array $activity): void
	{
		// A vote arrives as a Create(Note) aimed at one of our polls. It is
		// checked before the follow gate: any signed actor may vote on a
		// public poll, and the vote is recorded rather than imported as a
		// reply. An ordinary reply returns false and keeps the normal path.
		$object = $activity['object'] ?? null;
		if ($this->remotePolls !== null && is_array($object)
			&& $this->remotePolls->recordInboundVote($actor, $object, Time::toSecondPrecision($this->clock->now()))
		) {
			return;
		}

		$this->requireFollowedContent($actor);

		$post = $this->remotePostFactory->fromCreate($actor->actorId, $activity);

		if ($this->remotePolls !== null && is_array($object) && $post->objectType === RemoteObjectType::Question) {
			$this->remotePolls->importQuestion($post, $object, $post->activityId, Time::toSecondPrecision($this->clock->now()));
		}

		$this->insertRemotePost($post);

		// Import after the post is durable. The importer isolates every
		// attachment, so a slow or broken image never discards the post.
		$this->remoteMedia?->import($post, $activity['object'] ?? null);

		// An Update that arrived before this Create is applied now, so the
		// first version the timeline shows is the one the author last wrote.
		$this->applyPendingUpdate($post, $actor);
	}

	/**
	 * Applies an author's edit to a stored post, or holds it until the post
	 * arrives.
	 *
	 * Ownership is the stored row's actor, not just the signer: an Update may
	 * only change the object its own author wrote. A version that is not newer
	 * than the applied one is ignored, so retries and out-of-order deliveries
	 * converge on the newest version instead of flapping between them.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function handleUpdate(RemoteActor $actor, array $activity): void
	{
		$this->requireFollowedContent($actor);

		$target = $this->remotePosts->findPostByObjectId(
			$this->remotePostFactory->targetId($activity['object'] ?? null, 'Update'),
		);

		if ($target === null) {
			// The Create may still be in flight. Holding the edit is what
			// keeps an out-of-order pair from publishing superseded text. The
			// factory validates ownership and content before anything is held.
			$this->pendingUpdates?->store($this->remotePostFactory->pendingUpdate(
				$actor->actorId,
				$activity,
				Time::toSecondPrecision($this->clock->now()),
			));

			return;
		}

		if ($target->actorId !== $actor->actorId) {
			throw new InboxRejectedException('actor_mismatch', 'An actor may only update its own post.');
		}

		$updated = $this->remotePostFactory->fromUpdate($target, $activity);

		if ($target->updatedAt !== null && $updated->updatedAt !== null && $updated->updatedAt <= $target->updatedAt) {
			// A replay or an older version delivered late: the applied version
			// stays, and the peer's next newer edit will still apply.
			return;
		}

		$this->remotePosts->updatePostContent(
			$updated->objectId,
			$actor->actorId,
			$updated->content,
			$updated->contentWarning,
			$updated->updatedAt ?? Time::toSecondPrecision($this->clock->now()),
		);

		$object = $activity['object'] ?? null;
		if ($this->remotePolls !== null && is_array($object) && $updated->objectType->value === 'question') {
			$this->remotePolls->importQuestion(
				$updated,
				$object,
				$updated->activityId,
				$updated->updatedAt ?? Time::toSecondPrecision($this->clock->now()),
			);
		}
	}

	private function applyPendingUpdate(RemotePost $post, RemoteActor $actor): void
	{
		$pending = $this->pendingUpdates?->take(
			$post->objectId,
			$actor->actorId,
			Time::toSecondPrecision($this->clock->now()),
		);

		if ($pending === null) {
			return;
		}

		$this->remotePosts->updatePostContent(
			$post->objectId,
			$actor->actorId,
			$pending->content,
			$pending->contentWarning,
			$pending->updatedAt,
		);
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
	 * A quote response first, a Follow response second: both use Accept and
	 * Reject, and the embedded object says which request is answered.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function handleFollowOrQuoteResponse(RemoteActor $actor, array $activity, FollowState $state): void
	{
		if ($this->quotes?->handleResponse(
			$actor->actorId,
			$activity,
			$state === FollowState::Accepted ? QuoteState::Accepted : QuoteState::Rejected,
		) === true) {
			return;
		}

		$this->handleFollowResponse($actor, $activity, $state);
	}

	/**
	 * A Move is verified by both actor documents before any follow edge moves.
	 * The activity id is claimed by the replay guard; a duplicate Move returns
	 * without applying a second migration because the row is keyed by it.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function handleMove(RemoteActor $actor, array $activity): void
	{
		if ($this->accountMoves === null) {
			throw new InboxRejectedException(
				'unsupported_activity',
				'Account moves are not configured on this instance.',
			);
		}

		try {
			$this->accountMoves->receive($actor, $activity);
		} catch (AccountMoveException $exception) {
			throw new InboxRejectedException($exception->reason, $exception->getMessage());
		}
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function handleQuoteRequest(RemoteActor $actor, array $activity): void
	{
		if ($this->quotes === null) {
			throw new InboxRejectedException('unsupported_activity', 'Quote requests are not configured.');
		}

		$this->quotes->handleQuoteRequest($actor->actorId, $actor->inboxUrl, $activity);
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

		$locked = $this->profiles?->profile($localUserId)?->locked ?? false;
		$result = $this->followers->follow(
			new FollowActor(
				$localUserId,
				$actor->actorId,
				$actor->inboxUrl,
				$actor->sharedInboxUrl,
			),
			requiresApproval: $locked,
			followActivity: $activity,
		);

		// A pending request is not a follower yet: it must not receive
		// content or a notification until the owner accepts it.
		if ($result->follower->isPending()) {
			return;
		}

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
			// Some peers send only the reaction or Announce id. Try the reaction
			// store first: an actor that liked a public post need not be followed,
			// and the Announce handler would refuse it as "not_followed".
			if ($this->reactions !== null && $this->reactions->undoByActivityId($actor, $object)) {
				return;
			}

			$this->undoAnnounceById($actor, $object);

			return;
		}

		$type = is_array($object) ? ($object['type'] ?? null) : null;

		if ($type === 'Like') {
			$this->requireReactions()->undoLike($actor, $object);

			return;
		}

		if ($type === 'EmojiReact') {
			$this->requireReactions()->undoEmojiReact($actor, $object);

			return;
		}

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
			'Only Undo(Follow), Undo(Announce), Undo(Like) and Undo(EmojiReact) are supported.',
		);
	}

	private function requireReactions(): ReactionService
	{
		if ($this->reactions === null) {
			throw new InboxRejectedException(
				'unsupported_activity',
				'Reaction storage is not configured on this instance.',
			);
		}

		return $this->reactions;
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
