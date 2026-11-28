<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\InboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Domain\Interaction\Reaction;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Interaction\ReactionRepository;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Federation\RemoteEmoji;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function in_array;
use function is_array;
use function is_string;
use function rawurldecode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Federated Like and EmojiReact in both directions.
 *
 * The design decisions live here:
 *
 * - **Identity, not activity ids.** A reaction is keyed by (subject, object,
 *   kind, emoji). A peer's retransmission with a fresh activity id is still
 *   the same reaction, and an Undo removes it by the same key.
 * - **A local favourite is not a remote delivery.** A like/unlike is stored
 *   before anything is queued, so the local view is immediate and survives a
 *   failing or dead-lettered delivery; the queue owns retries.
 * - **Likes and emoji are separate counts.** An EmojiReact never increments
 *   the like count, and a like never becomes an emoji row.
 * - **Authorization is the post's audience, not the sender's claim.** Inbound
 *   reactions need a live local post, the author's block check and the same
 *   visibility rule a reader needs (direct posts are never reacted to).
 * - **Reactions are never mirrored to a remote object we cannot verify.**
 *   Outbound targets must be a stored remote post whose author the local
 *   account follows; the stored inbox is the delivery target, so no new
 *   network resolution happens on a write.
 */
final class ReactionService
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly FavouriteRepository $favourites,
		private readonly ReactionRepository $reactions,
		private readonly FollowerRepository $followers,
		private readonly FollowingRepository $following,
		private readonly BlockRepository $blocks,
		private readonly ActivityFactory $activities,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
		private readonly ?NotificationService $notifications = null,
		private readonly ?MuteRepository $mutes = null,
		private readonly ?RemotePostRepository $remotePosts = null,
		private readonly ?InboundBlockRepository $inboundBlocks = null,
		private readonly ?RemoteActorFetcher $remoteActors = null,
		private readonly ?ActorInboxResolver $actorInboxes = null,
	) {
	}

	/**
	 * Stores a verified inbound Like on a local post.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws InboxRejectedException when the object is unknown, unauthorized or the author blocks the actor
	 */
	public function receiveLike(RemoteActor $actor, array $activity): void
	{
		// Misskey and forks encode an emoji reaction as a Like carrying
		// `_misskey_reaction`; it is an Emoji reaction, not a favourite, so
		// the counts stay separate.
		$misskey = RemoteEmoji::misskeyReaction($activity);
		if ($misskey !== null) {
			$this->storeInbound($actor, $activity, ReactionKind::Emoji, $misskey['emoji'], $misskey['icon_url']);

			return;
		}

		$this->storeInbound($actor, $activity, ReactionKind::Like, null, null);
	}

	/**
	 * Stores a verified inbound EmojiReact on a local post.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws InboxRejectedException when the emoji, the object, the audience or the block check refuses it
	 */
	public function receiveEmojiReact(RemoteActor $actor, array $activity): void
	{
		$emoji = RemoteEmoji::fromActivity($activity);

		$this->storeInbound($actor, $activity, ReactionKind::Emoji, $emoji['emoji'], $emoji['icon_url']);
	}

	/**
	 * @param array<string, mixed>|string $object the embedded Like or its id
	 *
	 * @throws InboxRejectedException
	 */
	public function undoLike(RemoteActor $actor, array|string $object): void
	{
		// An Undo of a Misskey-style reaction still names the Like type; the
		// stored row is an Emoji reaction. Rows written before this mapping
		// existed are plain Likes, so a kind mismatch falls back to Like.
		if (is_array($object) && RemoteEmoji::misskeyReaction($object) !== null) {
			try {
				$this->undo($actor, $object, ReactionKind::Emoji);

				return;
			} catch (InboxRejectedException $exception) {
				if ($exception->reason !== 'invalid_activity') {
					throw $exception;
				}
			}
		}

		$this->undo($actor, $object, ReactionKind::Like);
	}

	/**
	 * @param array<string, mixed>|string $object the embedded EmojiReact or its id
	 *
	 * @throws InboxRejectedException
	 */
	public function undoEmojiReact(RemoteActor $actor, array|string $object): void
	{
		$this->undo($actor, $object, ReactionKind::Emoji);
	}

	/**
	 * Handles an Undo whose object is only an activity id.
	 *
	 * @return bool whether a stored reaction matched; false lets the caller try
	 *              the Announce interpretation of the same id
	 *
	 * @throws InboxRejectedException when the id belongs to another actor
	 */
	public function undoByActivityId(RemoteActor $actor, string $activityId): bool
	{
		$stored = $this->reactions->findByActivityId(trim($activityId));
		if ($stored === null) {
			return false;
		}

		if ($stored->direction !== ReactionDirection::In || $stored->subjectId !== $actor->actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'An actor may only undo its own reaction.',
			);
		}

		$this->reactions->remove(
			ReactionDirection::In,
			$stored->subjectId,
			$stored->objectId,
			$stored->kind,
			$stored->emoji,
		);

		return true;
	}

	/**
	 * The neutral interaction projection of one local post.
	 *
	 * `favourites_count` is local favourites plus inbound remote likes; emoji
	 * reactions have their own list and never inflate it. An emoji's stored
	 * icon URL is not returned: the core does not vouch for a remote image.
	 *
	 * @return array<string, mixed>
	 */
	public function interactionsFor(Post $post, ?string $viewerId): array
	{
		$counts = $this->inboundCounts([$post->id])[$post->id];

		$emoji = [];
		foreach ($counts['emojis'] as $value => $total) {
			$emoji[] = ['emoji' => $value, 'count' => $total];
		}

		return [
			'favourites_count' => $this->favourites->countForPost($post->id) + $counts['likes'],
			'emoji_reactions' => $emoji,
			'viewer' => [
				'favourited' => $viewerId !== null && $this->favourites->exists($viewerId, $post->id),
				'reactions' => [],
			],
		];
	}

	/**
	 * The favourite count a Mastodon status shows: local favourites plus
	 * inbound remote likes, never emoji reactions.
	 */
	public function favouritesCountFor(Post $post): int
	{
		$counts = $this->inboundCounts([$post->id])[$post->id];

		return $this->favourites->countForPost($post->id) + $counts['likes'];
	}

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, int>
	 */
	public function favouritesCountsForPosts(array $postIds): array
	{
		$counts = $this->inboundCounts($postIds);
		$result = $this->favourites->countsForPosts($postIds);
		foreach ($result as $postId => $local) {
			$result[$postId] = $local + ($counts[$postId]['likes'] ?? 0);
		}

		return $result;
	}

	/**
	 * The stored remote post a local account may react to.
	 *
	 * @throws ReactionException when the post is unknown, its author is not
	 *                           followed or either account has blocked the other
	 */
	public function remotePostFor(string $userId, string $objectId): RemotePost
	{
		return $this->findRemotePost($userId, $objectId, false);
	}

	/**
	 * Resolve a post only to remove local reaction state after a follow or block
	 * edge has changed. Cleanup never contacts an actor that has blocked this
	 * account, or an actor the local account has blocked.
	 */
	private function remotePostForUndo(string $userId, string $objectId): RemotePost
	{
		return $this->findRemotePost($userId, $objectId, true);
	}

	private function findRemotePost(string $userId, string $objectId, bool $allowRelationlessCleanup): RemotePost
	{
		$objectId = trim($objectId);
		if ($objectId === '' || $this->remotePosts === null) {
			throw new ReactionException('remote_post_not_found', 'Remote post not found.');
		}

		$post = $this->remotePosts->findPostByObjectId($objectId);
		if ($post === null || $post->deletedAt !== null) {
			throw new ReactionException('remote_post_not_found', 'Remote post not found.');
		}

		if (!$allowRelationlessCleanup) {
			if ($this->blocks->findByActor($userId, $post->actorId) !== null) {
				throw new ReactionException('blocked_actor', 'This actor is blocked.');
			}
			if ($this->inboundBlocks?->isBlocked($userId, $post->actorId) === true) {
				throw new ReactionException('blocked_actor', 'This remote actor has blocked this account.');
			}

			if ($post->audience === Visibility::Direct) {
				throw new ReactionException('remote_post_not_found', 'Remote post not found.');
			}

			$followed = $this->following->find($userId, $post->actorId);
			$acceptedFollow = $followed !== null && $followed->state === FollowState::Accepted;
			$isPublic = $post->audience === Visibility::Public || $post->audience === Visibility::Unlisted;
			// Legacy rows have no stored audience. Keep the old follow gate for
			// them instead of guessing that an unclassified object is public.
			$isFollowOnly = $post->audience === Visibility::Followers || $post->audience === null;

			if (!$isPublic && !($isFollowOnly && $acceptedFollow)) {
				throw new ReactionException(
					'remote_post_not_followed',
					'Follow the author before reacting to this post.',
				);
			}
		}

		return $post;
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ReactionException
	 */
	public function likeRemote(string $userId, string $objectId): array
	{
		$post = $this->remotePostFor($userId, $objectId);
		$existing = $this->reactions->find(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Like,
			null,
		);
		// Resolve the inbox before writing so an unreachable actor leaves no
		// local reaction the peer never saw; an idempotent retry skips the
		// fetch entirely.
		$inbox = $existing === null && $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$reaction = new Reaction(
			direction: ReactionDirection::Out,
			subjectId: $userId,
			objectId: $post->objectId,
			localPostId: null,
			kind: ReactionKind::Like,
			emoji: null,
			iconUrl: null,
			// Each lifecycle gets its own activity id: a Like→Undo→Like
			// sequence must not look like a retransmission to the peer's
			// replay log. The raw clock keeps sub-second toggles distinct.
			activityId: $this->activities->likeId($userId, $post->objectId, $this->clock->now()),
			createdAt: $this->now(),
		);

		if ($this->insertOutbound($reaction) && $inbox !== null) {
			$this->pushDelivery($userId, $inbox, $this->activities->like($userId, $post->objectId, $reaction->activityId));
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ReactionException
	 */
	public function unlikeRemote(string $userId, string $objectId): array
	{
		$post = $this->remotePostForUndo($userId, $objectId);
		$stored = $this->reactions->find(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Like,
			null,
		);
		if ($stored === null) {
			return $this->remoteInteractionsFor($userId, $post);
		}

		// A failed Undo must leave the local like in place so a retry can
		// still reach the peer, so the inbox resolves before the row goes.
		$inbox = $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$removed = $this->reactions->remove(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Like,
			null,
		);

		if ($removed && $inbox !== null) {
			$this->pushDelivery($userId, $inbox, $this->activities->undoLike($userId, $post->objectId, $stored->activityId));
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/** @return array<string, mixed> */
	public function boostRemote(string $userId, string $objectId): array
	{
		$post = $this->remotePostFor($userId, $objectId);
		$existing = $this->reactions->find(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Boost,
			null,
		);
		$inbox = $existing === null && $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$createdAt = $this->now();
		$reaction = new Reaction(
			direction: ReactionDirection::Out,
			subjectId: $userId,
			objectId: $post->objectId,
			localPostId: null,
			kind: ReactionKind::Boost,
			emoji: null,
			iconUrl: null,
			activityId: $this->activities->remoteBoostId($userId, $post->objectId, $createdAt),
			createdAt: $createdAt,
		);

		if ($this->insertOutbound($reaction) && $inbox !== null) {
			$this->pushDelivery(
				$userId,
				$inbox,
				$this->activities->announceRemote($userId, $post->objectId, $reaction->activityId),
			);
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/** @return array<string, mixed> */
	public function unboostRemote(string $userId, string $objectId): array
	{
		$post = $this->remotePostForUndo($userId, $objectId);
		$stored = $this->reactions->find(ReactionDirection::Out, $userId, $post->objectId, ReactionKind::Boost, null);
		if ($stored === null) {
			return $this->remoteInteractionsFor($userId, $post);
		}

		$inbox = $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$removed = $this->reactions->remove(ReactionDirection::Out, $userId, $post->objectId, ReactionKind::Boost, null);

		if ($removed && $inbox !== null) {
			$this->pushDelivery(
				$userId,
				$inbox,
				$this->activities->undoRemoteAnnounce($userId, $post->objectId, $stored->activityId),
			);
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ReactionException
	 */
	public function reactToRemote(string $userId, string $objectId, string $emoji): array
	{
		$post = $this->remotePostFor($userId, $objectId);
		$emoji = RemoteEmoji::forOutbound($emoji);
		$existing = $this->reactions->find(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Emoji,
			$emoji,
		);
		$inbox = $existing === null && $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$reaction = new Reaction(
			direction: ReactionDirection::Out,
			subjectId: $userId,
			objectId: $post->objectId,
			localPostId: null,
			kind: ReactionKind::Emoji,
			emoji: $emoji,
			iconUrl: null,
			activityId: $this->activities->reactionId($userId, $post->objectId, $emoji, $this->clock->now()),
			createdAt: $this->now(),
		);

		if ($this->insertOutbound($reaction) && $inbox !== null) {
			$this->pushDelivery(
				$userId,
				$inbox,
				$this->activities->emojiReact($userId, $post->objectId, $emoji, $reaction->activityId),
			);
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ReactionException
	 */
	public function unreactFromRemote(string $userId, string $objectId, string $emoji): array
	{
		$post = $this->remotePostForUndo($userId, $objectId);
		$emoji = RemoteEmoji::forOutbound($emoji);
		$stored = $this->reactions->find(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Emoji,
			$emoji,
		);
		if ($stored === null) {
			return $this->remoteInteractionsFor($userId, $post);
		}

		$inbox = $this->mayDeliverRemoteInteraction($userId, $post->actorId)
			? $this->remoteInbox($userId, $post->actorId)
			: null;
		$removed = $this->reactions->remove(
			ReactionDirection::Out,
			$userId,
			$post->objectId,
			ReactionKind::Emoji,
			$emoji,
		);

		if ($removed && $inbox !== null) {
			$this->pushDelivery(
				$userId,
				$inbox,
				$this->activities->undoEmojiReact($userId, $post->objectId, $emoji, $stored->activityId),
			);
		}

		return $this->remoteInteractionsFor($userId, $post);
	}

	/**
	 * The viewer's own state on a remote post.
	 *
	 * A remote post has no trusted count: the core only knows what its local
	 * accounts sent, so `favourites_count` is one or zero and the emoji list
	 * is the viewer's own reactions. Presenting a remote total would require a
	 * count fetch the core does not perform on a read.
	 *
	 * @return array<string, mixed>
	 */
	public function remoteInteractionsFor(string $userId, RemotePost $post): array
	{
		$rows = $this->reactions->forSubject(
			ReactionDirection::Out,
			$userId,
			[$post->objectId],
		)[$post->objectId] ?? [];

		$favourited = false;
		$reblogged = false;
		$emoji = [];
		$viewerEmoji = [];
		foreach ($rows as $row) {
			if ($row->kind === ReactionKind::Like) {
				$favourited = true;
				continue;
			}
			if ($row->kind === ReactionKind::Boost) {
				$reblogged = true;
				continue;
			}

			$emoji[] = ['emoji' => $row->emojiKey(), 'count' => 1];
			$viewerEmoji[] = $row->emojiKey();
		}

		return [
			'favourites_count' => $favourited ? 1 : 0,
			'reblogs_count' => $reblogged ? 1 : 0,
			'emoji_reactions' => $emoji,
			'viewer' => [
				'favourited' => $favourited,
				'reblogged' => $reblogged,
				'reactions' => $viewerEmoji,
			],
		];
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function storeInbound(
		RemoteActor $actor,
		array $activity,
		ReactionKind $kind,
		?string $emoji,
		?string $iconUrl,
	): void {
		$object = $activity['object'] ?? null;
		if (!is_string($object) || trim($object) === '') {
			throw new InboxRejectedException('invalid_activity', 'A reaction needs an object id.');
		}

		$post = $this->localPostFor(trim($object));
		if ($post === null) {
			throw new InboxRejectedException('unknown_object', 'The reaction targets an unknown local post.');
		}

		$this->authorize($post, $actor);

		$activityId = $activity['id'] ?? null;
		if (!is_string($activityId) || trim($activityId) === '') {
			throw new InboxRejectedException('invalid_activity', 'A reaction needs an activity id.');
		}

		$reaction = new Reaction(
			direction: ReactionDirection::In,
			subjectId: $actor->actorId,
			objectId: $this->activities->noteId($post),
			localPostId: $post->id,
			kind: $kind,
			emoji: $emoji,
			iconUrl: $iconUrl,
			activityId: trim($activityId),
			createdAt: $this->now(),
		);

		try {
			$this->reactions->insert($reaction);
		} catch (DatabaseException $exception) {
			// A retransmission with a new activity id is the same identity.
			// Only a storage failure without a matching row is a real error.
			if ($this->reactions->find(
				ReactionDirection::In,
				$actor->actorId,
				$reaction->objectId,
				$kind,
				$emoji,
			) === null) {
				throw $exception;
			}

			return;
		}

		if ($post->authorId === $actor->actorId) {
			return;
		}

		if (in_array(
			$actor->actorId,
			$this->mutes?->notificationActorIds($post->authorId, $this->clock->now()) ?? [],
			true,
		)) {
			// A muted actor's reaction still counts; only its notification
			// is suppressed, which is what a mute means.
			return;
		}

		$this->notifications?->recordReaction($post->authorId, $actor->actorId, $post->id, $emoji);
	}

	/**
	 * @param array<string, mixed>|string $object
	 */
	private function undo(RemoteActor $actor, array|string $object, ReactionKind $kind): void
	{
		$stored = null;

		if (is_array($object)) {
			$expected = $kind === ReactionKind::Like ? 'Like' : 'EmojiReact';
			$embeddedType = $object['type'] ?? null;
			// Misskey wraps emoji reactions in Like activities, so the Undo of
			// one still says "Like" while the stored row is an Emoji reaction.
			$misskey = $kind === ReactionKind::Emoji ? RemoteEmoji::misskeyReaction($object) : null;
			if ($embeddedType !== $expected
				&& !($misskey !== null && $embeddedType === 'Like')
			) {
				throw new InboxRejectedException('invalid_activity', 'The Undo does not embed a ' . $expected . '.');
			}

			$embeddedActor = $object['actor'] ?? null;
			if (!is_string($embeddedActor) || $embeddedActor !== $actor->actorId) {
				throw new InboxRejectedException('actor_mismatch', 'An actor may only undo its own reaction.');
			}

			$target = $object['object'] ?? null;
			if (!is_string($target) || trim($target) === '') {
				throw new InboxRejectedException('invalid_activity', 'The embedded reaction needs an object id.');
			}

			$id = $object['id'] ?? null;
			if (is_string($id) && trim($id) !== '') {
				$stored = $this->reactions->findByActivityId(trim($id));
			}

			if ($stored === null) {
				$content = $object['content'] ?? null;
				$emoji = $kind === ReactionKind::Emoji
					? (is_string($content) && trim($content) !== ''
						? trim($content)
						: $misskey['emoji'] ?? null)
					: null;
				$stored = $this->reactions->find(
					ReactionDirection::In,
					$actor->actorId,
					trim($target),
					$kind,
					$emoji,
				);
			}
		} elseif (trim($object) !== '') {
			$stored = $this->reactions->findByActivityId(trim($object));

			if ($stored === null) {
				// Some peers send the note URL. Removing by identity is safe
				// and makes the already-satisfied Undo a no-op.
				$post = $this->localPostFor(trim($object));
				if ($post !== null) {
					$this->reactions->remove(
						ReactionDirection::In,
						$actor->actorId,
						$this->activities->noteId($post),
						$kind,
						null,
					);
				}

				return;
			}
		} else {
			throw new InboxRejectedException('invalid_activity', 'An Undo needs an object.');
		}

		if ($stored === null) {
			// The reaction never arrived or was already undone; the peer's
			// intent is satisfied.
			return;
		}

		if ($stored->direction !== ReactionDirection::In || $stored->subjectId !== $actor->actorId) {
			throw new InboxRejectedException('actor_mismatch', 'An actor may only undo its own reaction.');
		}

		if ($stored->kind !== $kind) {
			throw new InboxRejectedException(
				'invalid_activity',
				'The Undo type does not match the stored reaction.',
			);
		}

		$this->reactions->remove(
			ReactionDirection::In,
			$stored->subjectId,
			$stored->objectId,
			$stored->kind,
			$stored->emoji,
		);
	}

	private function authorize(Post $post, RemoteActor $actor): void
	{
		if ($this->blocks->findByActor($post->authorId, $actor->actorId) !== null) {
			throw new InboxRejectedException('blocked_actor', 'The author blocks this actor.');
		}
		if ($this->inboundBlocks?->isBlocked($post->authorId, $actor->actorId) === true) {
			throw new InboxRejectedException('blocked_actor', 'This actor blocks the author.');
		}

		$allowed = match ($post->visibility) {
			Visibility::Public, Visibility::Unlisted => true,
			// A pending request is not an entitlement. The repository keeps
			// pending rows for the approval flow, so authorization must check
			// the state explicitly instead of treating any row as a follower.
			Visibility::Followers => $this->followers->findByActor(
				$post->authorId,
				$actor->actorId,
			)?->state === FollowState::Accepted,
			// A direct post is addressed to named accounts; a reaction would
			// create a public count for a private conversation.
			Visibility::Direct => false,
		};

		if (!$allowed) {
			throw new InboxRejectedException('not_authorized', 'The actor may not see this post.');
		}
	}

	private function localPostFor(string $objectId): ?Post
	{
		$prefix = $this->activities->baseUrl() . '/api/v1/posts/';
		if (!str_starts_with($objectId, $prefix)) {
			return null;
		}

		$encoded = substr($objectId, strlen($prefix));
		if ($encoded === '' || str_contains($encoded, '/')) {
			return null;
		}

		$post = $this->posts->find(rawurldecode($encoded));

		return $post === null || $post->isDeleted() ? null : $post;
	}

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, array{likes: int, emojis: array<string, int>}>
	 */
	private function inboundCounts(array $postIds): array
	{
		return $this->reactions->countsForPosts($postIds);
	}

	private function insertOutbound(Reaction $reaction): bool
	{
		try {
			$this->reactions->insert($reaction);

			return true;
		} catch (DatabaseException $exception) {
			if ($this->reactions->find(
				ReactionDirection::Out,
				$reaction->subjectId,
				$reaction->objectId,
				$reaction->kind,
				$reaction->emoji,
			) === null) {
				throw $exception;
			}

			return false;
		}
	}

	/**
	 * The inbox a remote interaction is delivered to.
	 *
	 * A stored follow edge wins because it was resolved by a signed fetch at
	 * follow time. The host's inbox resolver signs its actor request for
	 * peers that require authorized fetch and does not demand a signing key;
	 * the full actor document is the fallback and also covers hosts without
	 * an inbox resolver.
	 *
	 * @throws ReactionException when no usable inbox can be resolved
	 */
	private function remoteInbox(string $userId, string $actorId): string
	{
		$followed = $this->following->find($userId, $actorId);
		$inbox = $followed?->inboxUrl;
		if (is_string($inbox) && trim($inbox) !== '') {
			return trim($inbox);
		}

		$inbox = $this->actorInboxes?->inboxFor($actorId);
		if (is_string($inbox) && trim($inbox) !== '') {
			return trim($inbox);
		}

		if ($this->remoteActors === null) {
			throw new ReactionException(
				'remote_delivery_unreachable',
				'The remote author inbox cannot be resolved on this instance.',
			);
		}

		try {
			$actor = $this->remoteActors->fetch($actorId);
		} catch (UnknownActorException $exception) {
			throw new ReactionException('remote_delivery_unreachable', $exception->getMessage());
		}

		if ($actor->actorId !== $actorId || trim($actor->inboxUrl) === '') {
			throw new ReactionException(
				'remote_delivery_unreachable',
				'The remote actor document did not name a usable inbox.',
			);
		}

		return $actor->inboxUrl;
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function pushDelivery(string $userId, string $inbox, array $activity): void
	{
		$this->queue->push(JobType::FEDERATION_DELIVER, [
			'inbox' => $inbox,
			'activity' => $activity,
			'key_id' => $this->activities->keyId($userId),
		], subject: JobSubject::account($userId));
	}

	private function mayDeliverRemoteInteraction(string $userId, string $actorId): bool
	{
		return $this->blocks->findByActor($userId, $actorId) === null
			&& $this->inboundBlocks?->isBlocked($userId, $actorId) !== true;
	}

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision($this->clock->now());
	}
}
