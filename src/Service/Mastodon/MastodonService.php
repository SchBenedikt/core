<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\LocalActorSearch;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\Favourite;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\BoostService;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The local, disabled-by-default Mastodon compatibility surface.
 *
 * It reuses the existing post service for writes and the existing repositories
 * for reads. There is no second status table: a Mastodon status is a core
 * post with an API-shaped projection. Remote posts, remote boosts and
 * streaming are intentionally outside this local slice. Favourites, local
 * boosts, follow edges and notifications are local bookkeeping; notifications
 * are recorded from local favourites/follows/replies/boosts and verified
 * inbound Follows, and read from the notification repository without a second
 * account or post model.
 */
final class MastodonService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_SEARCH_OFFSET = 1000;

	public const DEFAULT_ACCOUNT_LIST_LIMIT = 40;

	public const MAX_ACCOUNT_LIST_LIMIT = 80;

	public const DEFAULT_NOTIFICATION_COUNT_LIMIT = 100;

	public const MAX_NOTIFICATION_COUNT_LIMIT = 1000;

	private const SEARCH_TYPES = ['accounts', 'statuses', 'hashtags'];

	public function __construct(
		private readonly Config $config,
		private readonly PostRepository $posts,
		private readonly PostService $postService,
		private readonly MastodonPresenter $presenter,
		private readonly ?LocalActorDirectory $localActors = null,
		private readonly ?MediaService $media = null,
		private readonly ?FavouriteRepository $favourites = null,
		private readonly ?Clock $clock = null,
		private readonly ?LocalRelationshipService $relationships = null,
		private readonly ?NotificationService $notifications = null,
		private readonly ?PostSearchRepository $searchPosts = null,
		private readonly ?LocalActorSearch $searchActors = null,
		private readonly ?PollVoteRepository $pollVotes = null,
		private readonly ?BoostRepository $boosts = null,
		private readonly ?BoostService $boostService = null,
	) {
	}

	public function isEnabled(): bool
	{
		return MastodonApiConfig::enabled($this->config);
	}

	public function requireEnabled(): void
	{
		if (!$this->isEnabled()) {
			throw new MastodonRequestException(
				404,
				'mastodon_api_disabled',
				'The Mastodon-compatible API is disabled on this instance.',
			);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function publicTimeline(int $limit, ?string $maxId, ?string $sinceId, ?string $minId): array
	{
		$this->requireEnabled();
		[$before, $beforeId, $after, $afterId] = $this->cursor($maxId, $sinceId ?? $minId);

		$posts = $this->posts->publicTimeline(
			$this->clamp($limit),
			$before,
			$beforeId,
			$after,
			$afterId,
		);

		return $this->presenter->statuses($posts);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function accountStatuses(
		string $accountId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
		?string $viewerId = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		[$before, $beforeId, $after, $afterId] = $this->cursor($maxId, $sinceId ?? $minId);

		$includeFollowers = $viewerId !== null
			&& ($viewerId === $accountId
				|| $this->relationships?->isLocalFollower($viewerId, $accountId) === true);

		$posts = $this->posts->authorTimeline(
			$accountId,
			$this->clamp($limit),
			$before,
			$beforeId,
			$after,
			$afterId,
			$includeFollowers,
		);

		return $this->presenter->statuses($posts, $viewerId);
	}

	/**
	 * The public profile of one local account.
	 *
	 * The shape is deliberately the same minimal account used by statuses and
	 * notifications; profile detail fields stay host-owned. Without a local
	 * directory there is no way to tell a local id from a remote one, so the
	 * host has opted out of that check and the projection is returned for the
	 * requested id.
	 *
	 * @return array<string, mixed>
	 */
	public function account(string $accountId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);

		return $this->presenter->account($accountId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function accountFollowers(
		string $accountId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		$relationships = $this->requireRelationships();

		return $this->accountPage(
			$relationships->followerAccountIds($accountId),
			$limit,
			$maxId,
			$sinceId,
			$minId,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function accountFollowing(
		string $accountId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		$relationships = $this->requireRelationships();

		return $this->accountPage(
			$relationships->followingAccountIds($accountId),
			$limit,
			$maxId,
			$sinceId,
			$minId,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(string $postId, ?string $viewerId): array
	{
		$this->requireEnabled();

		$boost = $this->boosts?->find($postId);
		if ($boost !== null) {
			return $this->presenter->boostStatus(
				$boost,
				$this->visiblePost($boost->postId, $viewerId),
				$viewerId,
			);
		}

		return $this->presenter->status($this->visiblePost($postId, $viewerId), $viewerId);
	}

	/**
	 * Both statuses and their direct replies, visible to the caller.
	 *
	 * The parent chain stops at the first post the viewer cannot see, so a
	 * public reply does not disclose a private ancestor. The descendant walk
	 * uses the same visibility check and a hard bound, so a long thread cannot
	 * turn one request into an unbounded traversal.
	 *
	 * @return array{ancestors: list<array<string, mixed>>, descendants: list<array<string, mixed>>}
	 */
	public function context(string $postId, ?string $viewerId): array
	{
		$this->requireEnabled();
		$post = $this->visiblePost($postId, $viewerId);

		$ancestors = [];
		$parentId = $post->inReplyToId;
		$seen = [$post->id => true];
		while ($parentId !== null && count($ancestors) < 20 && !isset($seen[$parentId])) {
			$parent = $this->posts->find($parentId);
			if ($parent === null || $parent->isDeleted()) {
				break;
			}

			try {
				$parent = $this->visiblePost($parentId, $viewerId);
			} catch (MastodonRequestException) {
				break;
			}

			$seen[$parentId] = true;
			array_unshift($ancestors, $parent);
			$parentId = $parent->inReplyToId;
		}

		$descendants = [];
		$queue = [$post->id];
		while ($queue !== [] && count($descendants) < 40) {
			$parentId = array_shift($queue);
			foreach (array_reverse($this->posts->repliesTo($parentId, 40)) as $reply) {
				if (isset($seen[$reply->id])) {
					continue;
				}

				try {
					$reply = $this->visiblePost($reply->id, $viewerId);
				} catch (MastodonRequestException) {
					continue;
				}

				$seen[$reply->id] = true;
				$descendants[] = $reply;
				$queue[] = $reply->id;
				if (count($descendants) >= 40) {
					break;
				}
			}
		}

		return [
			'ancestors' => $this->presenter->statuses($ancestors, $viewerId),
			'descendants' => $this->presenter->statuses($descendants, $viewerId),
		];
	}

	/**
	 * Reblogs a visible local status; a repeat is idempotent.
	 *
	 * @return array<string, mixed>
	 */
	public function reblogStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$post = $this->visiblePost($postId, $userId);

		try {
			$result = $this->requireBoostService()->boost($userId, $post->id);
		} catch (ValidationException $exception) {
			throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
		} catch (PostNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'Status not found.');
		}

		return $this->presenter->boostStatus($result->boost, $post, $userId);
	}

	/**
	 * Removes the caller's reblog; an absent reblog is a successful no-op.
	 *
	 * @return array<string, mixed>
	 */
	public function unreblogStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$post = $this->visiblePost($postId, $userId);
		$this->requireBoostService()->unboost($userId, $post->id);

		return $this->presenter->status($post, $userId);
	}

	/**
	 * Reads one local poll, including the viewer's own choices when the host
	 * supplied an authenticated user id.
	 *
	 * @return array<string, mixed>
	 */
	public function showPoll(string $pollId, ?string $viewerId): array
	{
		$this->requireEnabled();

		$post = $this->pollPost($pollId, $viewerId);
		$poll = $this->presenter->poll($post, $viewerId);
		if ($poll === null) {
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}

		return $poll;
	}

	/**
	 * Records one local account's choices and returns the updated poll.
	 *
	 * A retry with the same positions is an idempotent success; a different
	 * vote from the same account is refused. The repository's primary key is
	 * the race-safe authority, while this check keeps the response friendly.
	 *
	 * @param list<int|string> $choices option positions from the Mastodon request
	 *
	 * @return array<string, mixed>
	 */
	public function votePoll(string $userId, string $pollId, array $choices): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$post = $this->pollPost($pollId, $userId);
		$poll = $post->poll;
		if ($poll === null) {
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}

		if ($poll->isExpired($this->now())) {
			throw new MastodonRequestException(422, 'poll_ended', 'This poll has ended.');
		}

		$positions = $this->pollChoices($choices, $poll);
		$votes = $this->requirePollVotes();
		$existing = $this->findPollVote($votes, $post->id, $userId);
		if ($existing !== null) {
			if ($existing->positions === $positions) {
				return $this->presenter->poll($post, $userId, $existing, true) ?? [];
			}

			throw new MastodonRequestException(422, 'already_voted', 'You have already voted in this poll.');
		}

		$vote = new PollVote(
			postId: $post->id,
			userId: $userId,
			positions: $positions,
			createdAt: $this->now(),
		);

		try {
			$votes->insert($vote);
		} catch (DatabaseException $exception) {
			// Another request may have inserted the same row between the read
			// above and this insert. Return the winner for an identical retry;
			// anything else is either a real conflict or a missing table.
			$existing = $this->findPollVote($votes, $post->id, $userId);
			if ($existing !== null && $existing->positions === $positions) {
				return $this->presenter->poll($post, $userId, $existing, true) ?? [];
			}

			if ($existing !== null) {
				throw new MastodonRequestException(422, 'already_voted', 'You have already voted in this poll.');
			}

			throw new MastodonRequestException(
				503,
				'polls_not_configured',
				'Poll voting storage is not configured.',
			);
		}

		$updated = $this->posts->find($post->id);
		if ($updated === null || $updated->poll === null) {
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}

		return $this->presenter->poll($updated, $userId, $vote, true) ?? [];
	}

	/**
	 * @param list<string> $mediaIds
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * @param array<string, mixed>|null $poll the raw Mastodon `poll` parameter
	 */
	public function createStatus(
		string $userId,
		string $content,
		Visibility $visibility,
		?string $spoilerText,
		array $mediaIds,
		?string $idempotencyKey,
		?array $poll = null,
		?string $inReplyToId = null,
		array $recipientIds = [],
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$pollModel = $poll === null ? null : $this->pollFromInput($poll);
		$postId = $idempotencyKey === null
			? null
			: 'mastodon-' . substr(hash('sha256', $userId . "\0" . $idempotencyKey), 0, 32);

		try {
			$post = $this->postService->create(new CreatePost(
				authorId: $userId,
				content: $content,
				visibility: $visibility,
				contentWarning: $spoilerText,
				attachmentIds: $mediaIds,
				postId: $postId,
				poll: $pollModel,
				inReplyToId: $inReplyToId,
				recipientIds: $recipientIds,
			));
		} catch (DatabaseException $exception) {
			// A repeated idempotency key maps to the same deterministic post id.
			// The row already exists, so return it instead of inserting a
			// duplicate; a real storage failure still propagates.
			if ($postId === null) {
				throw $exception;
			}

			$existing = $this->posts->find($postId);
			if ($existing === null) {
				throw $exception;
			}

			if ($existing->isDeleted()) {
				throw new MastodonRequestException(404, 'not_found', 'Status not found.');
			}

			$post = $existing;
		}

		return $this->presenter->status($post, $userId);
	}

	public function deleteStatus(string $userId, string $postId): void
	{
		$this->requireEnabled();

		try {
			$this->postService->delete($userId, $postId);
		} catch (PostNotFoundException|PostAccessDeniedException) {
			throw new MastodonRequestException(404, 'not_found', 'Status not found.');
		}
	}

	/**
	 * Adds the caller's favourite to a visible local status.
	 *
	 * The repository's (user, post) key makes a repeat idempotent; a race is
	 * resolved by re-reading the winner instead of returning a storage error.
	 *
	 * @return array<string, mixed>
	 */
	public function favouriteStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$post = $this->visiblePost($postId, $userId);
		$favourites = $this->requireFavourites();

		try {
			$favourites->insert(new Favourite(
				userId: $userId,
				postId: $post->id,
				createdAt: $this->now(),
			));
		} catch (DatabaseException $exception) {
			if (!$favourites->exists($userId, $post->id)) {
				throw $exception;
			}
		}

		if ($post->authorId !== $userId) {
			$this->notifications?->recordFavourite($post->authorId, $userId, $post->id);
		}

		return $this->presenter->status($post, $userId);
	}

	/**
	 * Removes the caller's favourite from a visible local status.
	 *
	 * Removing an absent favourite is a successful no-op, matching Mastodon's
	 * idempotent unfavourite semantics.
	 *
	 * @return array<string, mixed>
	 */
	public function unfavouriteStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$post = $this->visiblePost($postId, $userId);
		$this->requireFavourites()->remove($userId, $post->id);

		return $this->presenter->status($post, $userId);
	}

	/**
	 * Stores one image through the same MediaService the core upload route uses.
	 *
	 * A client `Idempotency-Key` becomes a deterministic internal source key.
	 * `MediaService::upload()` already re-reads an attachment by source URL
	 * before writing, so a retry returns the original row and blobs without a
	 * second table. The key is scoped to the local user.
	 *
	 * @return array<string, mixed>
	 */
	public function uploadMedia(
		string $userId,
		string $bytes,
		?string $description = null,
		?string $idempotencyKey = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$this->requireMedia();

		$sourceUrl = $idempotencyKey === null
			? null
			: 'urn:alohasocial:mastodon-media:' . hash('sha256', $userId . "\0" . $idempotencyKey);

		try {
			$attachment = $this->media->upload(
				ownerId: $userId,
				bytes: $bytes,
				altText: $description,
				sourceUrl: $sourceUrl,
			);
		} catch (StorageException) {
			throw new MastodonRequestException(
				503,
				'media_storage_unavailable',
				'The media file could not be stored.',
			);
		}

		return $this->presenter->media($this->media->present($attachment));
	}

	/**
	 * Updates the description of an unattached attachment the caller owns.
	 *
	 * Mastodon only exposes metadata updates before the media is attached to a
	 * status. Keeping the same rule here means a published post cannot keep a
	 * link to an attachment whose description later changed.
	 *
	 * @return array<string, mixed>
	 */
	public function updateMedia(
		string $userId,
		string $mediaId,
		bool $hasDescription,
		?string $description,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$media = $this->requireMedia();

		$attachment = $this->ownedUnattachedMedia($media, $userId, $mediaId);
		if ($hasDescription) {
			$attachment = $media->updateAltText($attachment, $description);
		}

		return $this->presenter->media($media->present($attachment));
	}

	/**
	 * Deletes an unattached attachment the caller owns.
	 *
	 * A missing or foreign id is hidden as 404. An attached attachment is a
	 * 422 because Mastodon distinguishes that usage conflict explicitly.
	 */
	public function deleteMedia(string $userId, string $mediaId): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$media = $this->requireMedia();

		$attachment = $media->find($mediaId);
		if ($attachment === null || $attachment->ownerId !== $userId) {
			throw new MastodonRequestException(404, 'not_found', 'Media attachment not found.');
		}

		if ($media->isAttachedToPost($attachment)) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'Media attachment is currently used by a status.',
			);
		}

		$media->discard($attachment);
	}

	/**
	 * Follows one local account and returns the resulting relationship.
	 *
	 * A repeat is idempotent through the follower identity; the route returns
	 * the same relationship without creating a second edge.
	 *
	 * @return array<string, mixed>
	 */
	public function followAccount(string $userId, string $targetId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$this->requireLocalAccount($targetId);
		$this->requireRelationships()->follow($userId, $targetId);

		return $this->requireRelationships()->relationship($userId, $targetId);
	}

	/**
	 * Removes a local follow and returns the resulting relationship.
	 *
	 * Unfollowing an absent local edge is a successful no-op, matching
	 * Mastodon's idempotent unfollow response.
	 *
	 * @return array<string, mixed>
	 */
	public function unfollowAccount(string $userId, string $targetId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$this->requireLocalAccount($targetId);
		$this->requireRelationships()->unfollow($userId, $targetId);

		return $this->requireRelationships()->relationship($userId, $targetId);
	}

	/**
	 * @param list<string> $targetIds
	 *
	 * @return list<array<string, mixed>>
	 */
	public function relationships(string $userId, array $targetIds): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		foreach ($targetIds as $targetId) {
			$this->requireLocalAccount($targetId);
		}

		return $this->requireRelationships()->relationships($userId, $targetIds);
	}

	/**
	 * @param list<string> $types
	 * @param list<string> $excludeTypes
	 *
	 * @return list<array<string, mixed>>
	 */
	public function notifications(
		string $userId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
		array $types = [],
		array $excludeTypes = [],
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$service = $this->requireNotifications();
		try {
			[$before, $beforeId, $after, $afterId] = $this->notificationCursor(
				$service,
				$userId,
				$maxId,
				$sinceId ?? $minId,
			);
		} catch (DatabaseException) {
			throw new MastodonRequestException(
				503,
				'notifications_unavailable',
				'Notification storage is not available.',
			);
		}

		$include = $this->notificationTypes($types);
		if ($types !== [] && $include === []) {
			return [];
		}

		try {
			$notifications = $service->listFor(
				$userId,
				$this->clamp($limit),
				$before,
				$beforeId,
				$after,
				$afterId,
				$include,
				$this->notificationTypes($excludeTypes),
			);
		} catch (DatabaseException) {
			throw new MastodonRequestException(
				503,
				'notifications_unavailable',
				'Notification storage is not available.',
			);
		}

		$result = [];
		foreach ($notifications as $notification) {
			$status = $notification->postId === null
				? null
				: $this->posts->find($notification->postId);
			if ($status?->isDeleted() === true) {
				$status = null;
			}

			$result[] = $this->presenter->notification($notification, $status);
		}

		return $result;
	}

	/**
	 * Dismisses one notification by setting its durable read marker.
	 *
	 * The id is scoped to the caller. A missing or foreign id is a 404; a
	 * repeat dismiss of the caller's already-read row is a successful no-op.
	 */
	public function dismissNotification(string $userId, string $id): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$service = $this->requireNotifications();

		try {
			if (!$service->markRead($userId, $id, $this->now())) {
				throw new MastodonRequestException(404, 'not_found', 'Notification not found.');
			}
		} catch (DatabaseException) {
			throw new MastodonRequestException(
				503,
				'notifications_unavailable',
				'Notification storage is not available.',
			);
		}
	}

	/**
	 * Marks every unread notification of the caller as read.
	 *
	 * Repeating clear is a successful no-op. New notifications recorded after
	 * the clear are unread again because they are new rows.
	 */
	public function clearNotifications(string $userId): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$service = $this->requireNotifications();

		try {
			$service->markAllRead($userId, $this->now());
		} catch (DatabaseException) {
			throw new MastodonRequestException(
				503,
				'notifications_unavailable',
				'Notification storage is not available.',
			);
		}
	}

	/**
	 * Returns the capped unread count with the same local filters as the list.
	 *
	 * @param list<string> $types
	 * @param list<string> $excludeTypes
	 */
	public function notificationUnreadCount(
		string $userId,
		int $limit,
		array $types = [],
		array $excludeTypes = [],
		?string $accountId = null,
	): int {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$service = $this->requireNotifications();

		$include = $this->notificationTypes($types);
		if ($types !== [] && $include === []) {
			return 0;
		}

		try {
			$count = $service->unreadCount(
				$userId,
				$accountId === '' ? null : $accountId,
				$include,
				$this->notificationTypes($excludeTypes),
			);
		} catch (DatabaseException) {
			throw new MastodonRequestException(
				503,
				'notifications_unavailable',
				'Notification storage is not available.',
			);
		}

		return min(max(1, $limit), $count);
	}

	/**
	 * Local-only Mastodon v2 search shape.
	 *
	 * Only public/unlisted local statuses and local directory accounts are
	 * searched. Remote resolution, hashtags and full-text ranking are not
	 * invented; `hashtags` stays empty and `resolve` has no effect upstream.
	 *
	 * @return array{accounts: list<array<string, mixed>>, statuses: list<array<string, mixed>>, hashtags: list<never>}
	 */
	public function search(
		string $userId,
		string $query,
		?string $type,
		int $limit,
		int $offset,
		bool $following,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$query = trim($query);
		if ($query === '') {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'The search query is required.',
			);
		}

		$type = $type === null ? null : strtolower(trim($type));
		if ($type === '') {
			$type = null;
		}
		if ($type !== null && !in_array($type, self::SEARCH_TYPES, true)) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'Invalid search type.',
			);
		}

		$limit = $this->clamp($limit);
		$offset = max(0, min(self::MAX_SEARCH_OFFSET, $offset));

		$allowedAuthors = null;
		if ($following) {
			if ($this->relationships === null) {
				throw new MastodonRequestException(
					503,
					'search_not_configured',
					'Local following search is not configured.',
				);
			}

			$allowedAuthors = $this->relationships->localFollowingUserIds($userId);
		}

		$accounts = [];
		$statuses = [];

		if ($type === null || $type === 'accounts') {
			if ($this->searchActors === null) {
				throw new MastodonRequestException(
					503,
					'search_not_configured',
					'Local account search is not configured.',
				);
			}

			$accounts = array_map(
				fn (string $accountId): array => $this->presenter->account($accountId),
				$this->searchActors->search($query, $limit, $offset, $allowedAuthors),
			);
		}

		if ($type === null || $type === 'statuses') {
			if ($this->searchPosts === null) {
				throw new MastodonRequestException(
					503,
					'search_not_configured',
					'Local status search is not configured.',
				);
			}

			$posts = $allowedAuthors === []
				? []
				: $this->searchPosts->search($query, $limit, $offset, $allowedAuthors);
			$statuses = $this->presenter->statuses($posts, $userId);
		}

		return [
			'accounts' => $accounts,
			'statuses' => $statuses,
			'hashtags' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function verifyCredentials(string $userId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		return $this->presenter->account($userId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function instance(): array
	{
		$this->requireEnabled();

		return $this->presenter->instance();
	}

	public function clamp(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	public function clampAccountList(int $limit): int
	{
		return max(1, min(self::MAX_ACCOUNT_LIST_LIMIT, $limit));
	}

	public function baseUrl(): string
	{
		return $this->presenter->baseUrl();
	}

	private function visiblePost(string $postId, ?string $viewerId): Post
	{
		try {
			return $this->postService->getVisible($postId, $viewerId);
		} catch (PostNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'Status not found.');
		}
	}

	/**
	 * Resolves the stable `poll-<postId>` projection back to its visible post.
	 *
	 * A poll has no separate id table. A malformed, missing or invisible id is
	 * the same 404 as a missing status, so the response cannot reveal whether
	 * the post exists.
	 */
	private function pollPost(string $pollId, ?string $viewerId): Post
	{
		if (!str_starts_with($pollId, 'poll-') || strlen($pollId) <= 5) {
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}

		$post = $this->visiblePost(substr($pollId, 5), $viewerId);
		if ($post->poll === null) {
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}

		return $post;
	}

	/**
	 * Validates the Mastodon `choices[]` payload against the stored poll.
	 *
	 * Positions are zero-based and must be unique. A single-choice poll accepts
	 * exactly one; a multiple-choice poll accepts one or more up to the option
	 * count. Unknown, duplicate and non-numeric choices are validation errors.
	 *
	 * @param array<mixed> $choices
	 *
	 * @return list<int>
	 */
	private function pollChoices(array $choices, Poll $poll): array
	{
		if (!array_is_list($choices) || $choices === []) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'choices must be a non-empty list of poll option positions.',
			);
		}

		$positions = [];
		foreach ($choices as $choice) {
			if (is_int($choice)) {
				$position = $choice;
			} elseif (is_string($choice) && preg_match('/^\d+$/', trim($choice)) === 1) {
				$position = (int) $choice;
			} else {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'choices must contain poll option positions.',
				);
			}

			if (!isset($poll->options[$position])) {
				throw new MastodonRequestException(422, 'validation_failed', 'A poll option does not exist.');
			}

			if (in_array($position, $positions, true)) {
				throw new MastodonRequestException(422, 'validation_failed', 'A poll option cannot be selected twice.');
			}

			$positions[] = $position;
		}

		if (!$poll->multiple && count($positions) !== 1) {
			throw new MastodonRequestException(422, 'validation_failed', 'This poll accepts exactly one choice.');
		}

		sort($positions);

		return $positions;
	}

	private function findPollVote(PollVoteRepository $votes, string $postId, string $userId): ?PollVote
	{
		try {
			return $votes->find($postId, $userId);
		} catch (DatabaseException $exception) {
			throw new MastodonRequestException(
				503,
				'polls_not_configured',
				'Poll voting storage is not configured.',
			);
		}
	}

	private function requirePollVotes(): PollVoteRepository
	{
		if ($this->pollVotes === null) {
			throw new MastodonRequestException(
				503,
				'polls_not_configured',
				'Poll voting storage is not configured.',
			);
		}

		return $this->pollVotes;
	}

	private function ownedUnattachedMedia(
		MediaService $media,
		string $userId,
		string $mediaId,
	): Attachment {
		$attachment = $media->find($mediaId);
		if ($attachment === null
			|| $attachment->ownerId !== $userId
			|| $media->isAttachedToPost($attachment)
		) {
			throw new MastodonRequestException(404, 'not_found', 'Media attachment not found.');
		}

		return $attachment;
	}

	/**
	 * @param list<string> $values
	 *
	 * @return list<NotificationType>
	 */
	private function notificationTypes(array $values): array
	{
		$types = [];
		foreach ($values as $value) {
			$type = NotificationType::tryFrom(trim($value));
			if ($type !== null) {
				$types[$type->value] = $type;
			}
		}

		return array_values($types);
	}

	/**
	 * @return array{0: ?\DateTimeImmutable, 1: ?string, 2: ?\DateTimeImmutable, 3: ?string}
	 */
	private function notificationCursor(
		NotificationService $service,
		string $userId,
		?string $maxId,
		?string $sinceId,
	): array {
		if ($maxId !== null && $maxId !== '' && $sinceId !== null && $sinceId !== '') {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'max_id and since_id/min_id cannot be combined.',
			);
		}

		$before = null;
		$beforeId = null;
		if ($maxId !== null && $maxId !== '') {
			$notification = $this->notificationCursorNotification($service, $userId, $maxId);
			$before = $notification->createdAt;
			$beforeId = $notification->id;
		}

		$after = null;
		$afterId = null;
		if ($sinceId !== null && $sinceId !== '') {
			$notification = $this->notificationCursorNotification($service, $userId, $sinceId);
			$after = $notification->createdAt;
			$afterId = $notification->id;
		}

		return [$before, $beforeId, $after, $afterId];
	}

	private function notificationCursorNotification(
		NotificationService $service,
		string $userId,
		string $id,
	): Notification {
		$notification = $service->find($id);
		if ($notification === null || $notification->recipientId !== $userId) {
			throw new MastodonRequestException(404, 'not_found', 'Notification cursor not found.');
		}

		return $notification;
	}

	private function requireRelationships(): LocalRelationshipService
	{
		if ($this->relationships === null) {
			throw new MastodonRequestException(
				503,
				'follows_not_configured',
				'Local relationship storage is not configured.',
			);
		}

		return $this->relationships;
	}

	private function requireNotifications(): NotificationService
	{
		if ($this->notifications === null) {
			throw new MastodonRequestException(
				503,
				'notifications_not_configured',
				'Notification storage is not configured.',
			);
		}

		return $this->notifications;
	}

	private function requireMedia(): MediaService
	{
		if ($this->media === null) {
			throw new MastodonRequestException(
				503,
				'media_not_configured',
				'Media storage is not configured.',
			);
		}

		return $this->media;
	}

	private function requireFavourites(): FavouriteRepository
	{
		if ($this->favourites === null) {
			throw new MastodonRequestException(
				503,
				'favourites_not_configured',
				'Favourite storage is not configured.',
			);
		}

		return $this->favourites;
	}

	private function requireBoostService(): BoostService
	{
		if ($this->boostService === null) {
			throw new MastodonRequestException(
				503,
				'boosts_not_configured',
				'Boost storage is not configured.',
			);
		}

		return $this->boostService;
	}

	/**
	 * Maps the Mastodon `poll` parameter into the core value object.
	 *
	 * `expires_in` wins when both expiration forms are present. The bounds are
	 * the same values the instance endpoint advertises, so a client cannot
	 * create a poll the server would describe as unsupported.
	 *
	 * @param array<string, mixed> $input
	 */
	private function pollFromInput(array $input): Poll
	{
		$rawOptions = $input['options'] ?? null;
		if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
			throw new MastodonRequestException(422, 'validation_failed', 'poll options must be a list.');
		}

		$options = [];
		foreach ($rawOptions as $rawOption) {
			if (!is_string($rawOption)) {
				throw new MastodonRequestException(422, 'validation_failed', 'poll options must be strings.');
			}

			$title = trim($rawOption);
			if ($title === '') {
				throw new MastodonRequestException(422, 'validation_failed', 'A poll option must not be empty.');
			}

			if (mb_strlen($title) > PollOption::MAX_TITLE_LENGTH) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'A poll option may be at most ' . PollOption::MAX_TITLE_LENGTH . ' characters long.',
				);
			}

			try {
				$options[] = new PollOption($title);
			} catch (InvalidArgumentException $exception) {
				throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
			}
		}

		if (count($options) < Poll::MIN_OPTIONS || count($options) > Poll::MAX_OPTIONS) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'A poll needs between ' . Poll::MIN_OPTIONS . ' and ' . Poll::MAX_OPTIONS . ' options.',
			);
		}

		return new Poll(
			options: $options,
			multiple: $this->pollBoolean($input['multiple'] ?? false, 'multiple'),
			hideTotals: $this->pollBoolean($input['hide_totals'] ?? false, 'hide_totals'),
			expiresAt: $this->pollExpiry($input),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function pollExpiry(array $input): DateTimeImmutable
	{
		$now = $this->now();
		$expiresIn = $input['expires_in'] ?? null;
		if ($expiresIn !== null) {
			if (!is_int($expiresIn) && !(is_string($expiresIn) && preg_match('/^\d+$/', trim($expiresIn)) === 1)) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'poll expires_in must be an integer number of seconds.',
				);
			}

			$seconds = (int) $expiresIn;
			if ($seconds < Poll::MIN_EXPIRY_SECONDS || $seconds > Poll::MAX_EXPIRY_SECONDS) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'poll expires_in must be between ' . Poll::MIN_EXPIRY_SECONDS
						. ' and ' . Poll::MAX_EXPIRY_SECONDS . ' seconds.',
				);
			}

			return $now->modify('+' . $seconds . ' seconds');
		}

		$expiresAt = $input['expires_at'] ?? null;
		if (is_string($expiresAt) && trim($expiresAt) !== '') {
			try {
				$parsed = Time::toSecondPrecision(new DateTimeImmutable(trim($expiresAt)));
			} catch (\Exception) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'poll expires_at must be a valid date and time.',
				);
			}

			$seconds = $parsed->getTimestamp() - $now->getTimestamp();
			if ($seconds < Poll::MIN_EXPIRY_SECONDS || $seconds > Poll::MAX_EXPIRY_SECONDS) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'poll expiry must be between ' . Poll::MIN_EXPIRY_SECONDS
						. ' and ' . Poll::MAX_EXPIRY_SECONDS . ' seconds from now.',
				);
			}

			return $parsed;
		}

		throw new MastodonRequestException(
			422,
			'validation_failed',
			'poll expires_in or expires_at is required.',
		);
	}

	private function pollBoolean(mixed $value, string $field): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) && ($value === 0 || $value === 1)) {
			return $value === 1;
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
				return true;
			}

			if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
				return false;
			}
		}

		throw new MastodonRequestException(422, 'validation_failed', 'poll ' . $field . ' must be a boolean.');
	}

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision(($this->clock ?? new SystemClock())->now());
	}

	/**
	 * Pages a stable account-id list with the Mongo-style id cursors Mastodon
	 * clients take from the Link header. The cursor must be part of this exact
	 * collection: a stale id from another account or page is a 404, not an
	 * accidental offset.
	 *
	 * @param list<string> $accountIds newest first
	 *
	 * @return list<array<string, mixed>>
	 */
	private function accountPage(
		array $accountIds,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$max = $maxId !== null && $maxId !== '' ? $maxId : null;
		$since = $sinceId !== null && $sinceId !== ''
			? $sinceId
			: ($minId !== null && $minId !== '' ? $minId : null);

		if ($max !== null && $since !== null) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'max_id and since_id/min_id cannot be combined.',
			);
		}

		$cursor = $max ?? $since;
		if ($cursor !== null) {
			$index = array_search($cursor, $accountIds, true);
			if ($index === false) {
				throw new MastodonRequestException(404, 'not_found', 'Account cursor not found.');
			}

			$accountIds = $max !== null
				? array_slice($accountIds, $index + 1)
				: array_slice($accountIds, 0, $index);
		}

		$page = array_slice($accountIds, 0, $this->clampAccountList($limit));

		return array_map(
			fn (string $accountId): array => $this->presenter->actorAccount($accountId),
			$page,
		);
	}

	/**
	 * @return array{0: ?\DateTimeImmutable, 1: ?string, 2: ?\DateTimeImmutable, 3: ?string}
	 */
	private function cursor(?string $maxId, ?string $sinceId): array
	{
		if ($maxId !== null && $maxId !== '' && $sinceId !== null && $sinceId !== '') {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'max_id and since_id/min_id cannot be combined.',
			);
		}

		$before = null;
		$beforeId = null;
		if ($maxId !== null && $maxId !== '') {
			$post = $this->cursorPost($maxId);
			$before = $post->publishedAt;
			$beforeId = $post->id;
		}

		$after = null;
		$afterId = null;
		if ($sinceId !== null && $sinceId !== '') {
			$post = $this->cursorPost($sinceId);
			$after = $post->publishedAt;
			$afterId = $post->id;
		}

		return [$before, $beforeId, $after, $afterId];
	}

	private function cursorPost(string $id): Post
	{
		$post = $this->posts->find($id);
		if ($post === null || $post->isDeleted() || !$post->visibility->isPubliclyVisible()) {
			throw new MastodonRequestException(404, 'not_found', 'Status cursor not found.');
		}

		return $post;
	}

	private function requireLocalAccount(string $accountId): void
	{
		if ($this->localActors !== null && !$this->localActors->has($accountId)) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}
	}
}
