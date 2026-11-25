<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\InboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\LocalActorSearch;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Domain\Post\Bookmark;
use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\Favourite;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostConflictException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Search\RemoteActorSearch;
use AlohaSocial\Core\Domain\Search\RemoteHandle;
use AlohaSocial\Core\Domain\Search\RemoteSearchException;
use AlohaSocial\Core\Domain\Search\TrendRepository;
use AlohaSocial\Core\Domain\Timeline\AccountList;
use AlohaSocial\Core\Domain\Timeline\AccountListNotFoundException;
use AlohaSocial\Core\Domain\Timeline\ListRepliesPolicy;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\AccountListService;
use AlohaSocial\Core\Service\BoostService;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\FollowingBlockedException;
use AlohaSocial\Core\Service\FollowingNotConfiguredException;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\PostHeldException;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\Federation\RemotePollException;
use AlohaSocial\Core\Service\Federation\RemotePollService;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Service\UpdatePost;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The disabled-by-default Mastodon compatibility surface.
 *
 * It reuses the existing post service for writes and the existing repositories
 * for reads. There is no second status table: a Mastodon status is a core
 * post with an API-shaped projection. Streaming is intentionally outside this
 * slice. Remote follows and reactions to cached remote posts use the shared
 * federation lifecycle, and remote poll reads/votes require an accepted follow
 * because stored Questions do not retain their original audience.
 * Local favourites, boosts, follow edges and notifications use the shared
 * repositories; outgoing remote follows use the shared federation lifecycle.
 * Notifications are recorded from local favourites/follows/replies/boosts and verified
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
		private readonly ?BookmarkRepository $bookmarks = null,
		private readonly ?MuteService $mutes = null,
		private readonly ?AccountListService $lists = null,
		private readonly ?TrendRepository $trends = null,
		private readonly ?LocalActorProfileProvider $profiles = null,
		private readonly ?FollowingService $federatedFollowing = null,
		private readonly ?FollowingRepository $followingRepository = null,
		private readonly ?RemoteActorFetcher $remoteActors = null,
		private readonly ?BlockRepository $blocks = null,
		private readonly ?InboundBlockRepository $inboundBlocks = null,
		private readonly ?RemotePollService $remotePolls = null,
		private readonly ?ReactionService $reactions = null,
		private readonly ?TimelineService $homeTimelines = null,
		private readonly ?RemotePostRepository $remotePosts = null,
		private readonly ?RemoteActorSearch $remoteAccountSearch = null,
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
	 * Public local statuses carrying a hashtag, in the Mastodon status shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function hashtagTimeline(string $tag, int $limit, ?string $maxId, ?string $userId): array
	{
		$this->requireEnabled();
		if ($this->homeTimelines === null) {
			throw new MastodonRequestException(503, 'timeline_not_configured', 'Hashtag timeline storage is not configured.');
		}
		[$before, $beforeId] = [null, null];
		if ($maxId !== null && $maxId !== '') {
			$post = $this->cursorPost($maxId);
			$before = $post->publishedAt;
			$beforeId = $post->id;
		}

		try {
			$posts = $this->homeTimelines->tag(
				$tag,
				$this->clamp($limit),
				$before?->format('Y-m-d\\TH:i:s\\Z'),
				$beforeId,
			);
		} catch (\AlohaSocial\Core\Service\InvalidHashtagException $exception) {
			throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
		}

		return $this->presenter->statuses($posts, $userId);
	}

	/**
	 * The authenticated account's combined local and followed-remote timeline.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function homeTimeline(string $userId, int $limit, ?string $maxId, ?string $sinceId): array
	{
		$this->requireEnabled();
		if ($this->homeTimelines === null || $this->remotePosts === null) {
			throw new MastodonRequestException(503, 'timeline_not_configured', 'Home timeline storage is not configured.');
		}

		if ($maxId !== null && $maxId !== '' && $sinceId !== null && $sinceId !== '') {
			throw new MastodonRequestException(422, 'validation_failed', 'max_id and since_id cannot be combined.');
		}

		$cursorId = null;
		$cursorTime = null;
		if ($maxId !== null && $maxId !== '') {
			[$cursorTime, $cursorId] = $this->homeCursor($userId, $maxId);
		}

		$after = null;
		$afterId = null;
		if ($sinceId !== null && $sinceId !== '') {
			[$after, $afterId] = $this->homeCursor($userId, $sinceId);
		}

		$entries = $this->homeTimelines->home(
			$userId,
			$this->clamp($limit),
			$cursorTime?->format('Y-m-d\\TH:i:s\\Z'),
			$cursorId,
		);

		if ($after !== null && $afterId !== null) {
			$entries = array_values(array_filter($entries, static fn (TimelineEntry $entry): bool =>
				$entry->publishedAt > $after
				|| ($entry->publishedAt == $after && strcmp($entry->id, $afterId) > 0)
			));
		}

		$statuses = [];
		foreach ($entries as $entry) {
			if ($entry->isBoost()) {
				$boost = $this->boosts?->find($entry->id);
				if ($boost !== null) {
					$post = $this->posts->find($entry->objectId);
					if ($post !== null && !$post->isDeleted()) {
						$statuses[] = $this->presenter->boostStatus($boost, $post, $userId);
					}
					continue;
				}
			}

			$localPost = $this->posts->find($entry->id);
			if ($localPost !== null && !$localPost->isDeleted()) {
				$statuses[] = $this->presenter->status($localPost, $userId);
				continue;
			}

			$remote = $this->remotePosts->findByActivityId($entry->id);
			if ($remote === null || $remote->deletedAt !== null) {
				continue;
			}

			if ($remote->isBoost()) {
				$object = $this->remotePosts->findPostByObjectId($remote->objectId);
				if ($object === null || $object->deletedAt !== null) {
					continue;
				}

				$interaction = $this->reactions?->remoteInteractionsFor($userId, $object) ?? [];
				$original = $this->presenter->remoteStatus(
					$object,
					$interaction['viewer']['favourited'] ?? false,
					$interaction['viewer']['reblogged'] ?? false,
				);
				$statuses[] = $this->presenter->remoteBoostStatus($remote, $original);
				continue;
			}

			$interaction = $this->reactions?->remoteInteractionsFor($userId, $remote) ?? [];
			$statuses[] = $this->presenter->remoteStatus(
				$remote,
				$interaction['viewer']['favourited'] ?? false,
				$interaction['viewer']['reblogged'] ?? false,
			);
		}

		return $statuses;
	}

	/**
	 * Resolve a Mastodon status id to a cursor that is present in this user's
	 * home timeline. Foreign and pending-follow rows cannot be used as cursors.
	 *
	 * @return array{0: DateTimeImmutable, 1: string}
	 */
	private function homeCursor(string $userId, string $id): array
	{
		$localFollowing = array_fill_keys($this->relationships?->localFollowingUserIds($userId) ?? [], true);
		$post = $this->posts->find($id);
		if ($post !== null && !$post->isDeleted()
			&& ($post->authorId === $userId || isset($localFollowing[$post->authorId]))
		) {
			return [$post->publishedAt, $post->id];
		}

		$boost = $this->boosts?->find($id);
		if ($boost !== null
			&& ($boost->userId === $userId || isset($localFollowing[$boost->userId]))
		) {
			return [$boost->createdAt, $boost->id];
		}

		$remote = $this->remotePosts?->findByActivityId($id)
			?? $this->remotePosts?->findPostByObjectId($id);
		if ($remote !== null && $remote->deletedAt === null
			&& $this->followingRepository?->find($userId, $remote->actorId)?->state === FollowState::Accepted
		) {
			return [$remote->publishedAt, $remote->activityId];
		}

		throw new MastodonRequestException(404, 'not_found', 'Home timeline cursor not found.');
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
	 * Look up a local account or resolve one exact remote Mastodon handle.
	 *
	 * @return array<string, mixed>
	 */
	public function lookupAccount(string $userId, string $acct): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$acct = trim($acct);
		if (str_starts_with($acct, '@')) {
			$acct = substr($acct, 1);
		}

		$localAccountId = $this->localAccountIdForAcct($acct);
		if ($localAccountId !== null) {
			return $this->presenter->account($localAccountId);
		}

		$handle = RemoteHandle::tryFrom($acct);
		if ($handle === null) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}
		if ($this->remoteAccountSearch === null) {
			throw new MastodonRequestException(
				503,
				'search_not_configured',
				'Remote account lookup is not configured.',
			);
		}

		try {
			$profile = $this->remoteAccountSearch->lookup($handle);
		} catch (RemoteSearchException) {
			throw new MastodonRequestException(503, 'remote_search_failed', 'Remote account lookup failed.');
		}

		if ($profile === null
			|| $this->blocks?->findByActor($userId, $profile->actorId) !== null
			|| $this->inboundBlocks?->isBlocked($userId, $profile->actorId) === true
		) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}

		return $this->presenter->remoteAccount($profile);
	}

	private function localAccountIdForAcct(string $acct): ?string
	{
		if (str_contains($acct, '@')) {
			$separator = strrpos($acct, '@');
			$username = substr($acct, 0, $separator);
			$domain = strtolower(substr($acct, $separator + 1));
			$instanceHost = strtolower((string) parse_url($this->presenter->baseUrl(), PHP_URL_HOST));
			if ($domain !== $instanceHost) {
				return null;
			}
			$acct = $username;
		}

		return $acct !== '' && $this->localActors?->has($acct) === true ? $acct : null;
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
		?string $viewerId = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		$relationships = $this->requireRelationships();

		if ($this->profiles?->profile($accountId)?->hideCollections === true && $viewerId !== $accountId) {
			return [];
		}

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
		?string $viewerId = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		$relationships = $this->requireRelationships();

		if ($this->profiles?->profile($accountId)?->hideCollections === true && $viewerId !== $accountId) {
			return [];
		}

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
		if ($this->isRemoteObjectTarget($postId)) {
			if ($viewerId === null) {
				throw new MastodonRequestException(404, 'not_found', 'Status not found.');
			}

			$post = $this->remotePost($viewerId, $postId);
			$interaction = $this->requireReactionService()->remoteInteractionsFor($viewerId, $post);

			return $this->presenter->remoteStatus($post, $interaction['viewer']['favourited'], $interaction['viewer']['reblogged']);
		}

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
		if ($this->isRemoteObjectTarget($postId)) {
			$post = $this->remotePost($userId, $postId);
			try {
				$interaction = $this->requireReactionService()->boostRemote($userId, $postId);
			} catch (ReactionException $exception) {
				throw $this->mapRemoteReactionException($exception);
			}

			return $this->presenter->remoteStatus($post, $interaction['viewer']['favourited'], $interaction['viewer']['reblogged']);
		}

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
		if ($this->isRemoteObjectTarget($postId)) {
			$post = $this->remotePost($userId, $postId);
			try {
				$interaction = $this->requireReactionService()->unboostRemote($userId, $postId);
			} catch (ReactionException $exception) {
				throw $this->mapRemoteReactionException($exception);
			}

			return $this->presenter->remoteStatus($post, $interaction['viewer']['favourited'], $interaction['viewer']['reblogged']);
		}

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
		if ($this->isRemotePollId($pollId)) {
			try {
				$this->requireRemotePollAccess($pollId, $viewerId);
				return $this->remoteMastodonPoll($this->requireRemotePolls()->present($pollId, $viewerId));
			} catch (RemotePollException $exception) {
				throw new MastodonRequestException($exception->status, $exception->reason, $exception->getMessage());
			}
		}

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
		if ($this->isRemotePollId($pollId)) {
			$positions = [];
			foreach ($choices as $choice) {
				if (is_int($choice)) {
					$positions[] = $choice;
				} elseif (is_string($choice) && preg_match('/^\\d+$/', trim($choice)) === 1) {
					$positions[] = (int) $choice;
				} else {
					throw new MastodonRequestException(422, 'validation_failed', 'choices must contain poll option positions.');
				}
			}

			try {
				$this->requireRemotePollAccess($pollId, $userId);
				return $this->remoteMastodonPoll($this->requireRemotePolls()->vote($userId, $pollId, $positions, $this->now()));
			} catch (RemotePollException $exception) {
				throw new MastodonRequestException($exception->status, $exception->reason, $exception->getMessage());
			}
		}

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
		?string $quotedStatusId = null,
		?string $language = null,
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
				quotedPostId: $quotedStatusId,
				language: $language,
			));
		} catch (PostHeldException $exception) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'This status is waiting for moderator review.',
			);
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
	 * Edits one of the caller's statuses.
	 *
	 * Mastodon treats an edit of a status the caller cannot see as "not
	 * found", so a foreign post and a missing one share the 404. The audience
	 * is not part of a Mastodon edit; only the neutral PATCH route narrows it.
	 *
	 * @param list<string>|null $mediaIds
	 *
	 * @return array<string, mixed>
	 */
	public function updateStatus(
		string $userId,
		string $postId,
		string $content,
		bool $spoilerProvided,
		?string $spoilerText,
		?array $mediaIds,
		?string $language = null,
		bool $languageProvided = false,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		try {
			$post = $this->postService->edit($userId, $postId, new UpdatePost(
				content: $content,
				contentWarning: $spoilerText,
				contentWarningProvided: $spoilerProvided,
				attachmentIds: $mediaIds,
				language: $language,
				languageProvided: $languageProvided,
			));
		} catch (PostNotFoundException|PostAccessDeniedException) {
			throw new MastodonRequestException(404, 'not_found', 'Status not found.');
		} catch (PostConflictException) {
			throw new MastodonRequestException(409, 'conflict', 'The status was edited concurrently.');
		}

		return $this->presenter->status($post, $userId);
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
		if ($this->isRemoteObjectTarget($postId)) {
			$post = $this->remotePost($userId, $postId);
			try {
				$interaction = $this->requireReactionService()->likeRemote($userId, $postId);
			} catch (ReactionException $exception) {
				throw $this->mapRemoteReactionException($exception);
			}

			return $this->presenter->remoteStatus($post, $interaction['viewer']['favourited'], $interaction['viewer']['reblogged']);
		}

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
		if ($this->isRemoteObjectTarget($postId)) {
			$post = $this->remotePost($userId, $postId);
			try {
				$interaction = $this->requireReactionService()->unlikeRemote($userId, $postId);
			} catch (ReactionException $exception) {
				throw $this->mapRemoteReactionException($exception);
			}

			return $this->presenter->remoteStatus($post, $interaction['viewer']['favourited'], $interaction['viewer']['reblogged']);
		}

		$post = $this->visiblePost($postId, $userId);
		$this->requireFavourites()->remove($userId, $post->id);

		return $this->presenter->status($post, $userId);
	}

	/**
	 * Adds a private bookmark for the caller.
	 *
	 * Bookmarks are not reactions: they have no public count and never create
	 * a notification. A repeated call is idempotent through the (user, post)
	 * key and returns the current status projection.
	 *
	 * @return array<string, mixed>
	 */
	public function bookmarkStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$post = $this->visiblePost($postId, $userId);
		$bookmarks = $this->requireBookmarks();

		if (!$bookmarks->exists($userId, $post->id)) {
			try {
				$bookmarks->insert(new Bookmark(
					userId: $userId,
					postId: $post->id,
					createdAt: $this->now(),
				));
			} catch (DatabaseException $exception) {
				if (!$bookmarks->exists($userId, $post->id)) {
					throw $exception;
				}
			}
		}

		return $this->presenter->status($post, $userId);
	}

	/**
	 * Removes the caller's bookmark; an absent bookmark is a successful no-op.
	 *
	 * @return array<string, mixed>
	 */
	public function unbookmarkStatus(string $userId, string $postId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		$post = $this->visiblePost($postId, $userId);
		$this->requireBookmarks()->remove($userId, $post->id);

		return $this->presenter->status($post, $userId);
	}

	/**
	 * The caller's private bookmark timeline, newest first.
	 *
	 * Statuses that are no longer visible or were soft-deleted are omitted.
	 * The cursor is a status id that must belong to this caller's bookmarks,
	 * so it cannot be used to probe another account's private list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function bookmarks(
		string $userId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$bookmarks = $this->requireBookmarks();

		[$before, $beforePostId, $after, $afterPostId] = $this->bookmarkCursor(
			$bookmarks,
			$userId,
			$maxId,
			$sinceId ?? $minId,
		);

		$posts = [];
		foreach ($bookmarks->forUser(
			$userId,
			$this->clamp($limit),
			$before,
			$beforePostId,
			$after,
			$afterPostId,
		) as $bookmark) {
			try {
				$post = $this->postService->getVisible($bookmark->postId, $userId);
			} catch (PostNotFoundException) {
				continue;
			}

			$posts[] = $post;
		}

		return $this->presenter->statuses($posts, $userId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function lists(string $userId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		return array_map(
			static fn (AccountList $list): array => $list->toArray(),
			$this->requireLists()->lists($userId),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function list(string $userId, string $listId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		return $this->listEntity($this->ownedList($userId, $listId));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createList(
		string $userId,
		?string $title,
		?string $repliesPolicy = null,
		?bool $exclusive = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		return $this->listEntity($this->requireLists()->create(
			$userId,
			$title,
			$repliesPolicy,
			$exclusive,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function updateList(
		string $userId,
		string $listId,
		?string $title = null,
		?string $repliesPolicy = null,
		?bool $exclusive = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		try {
			return $this->listEntity($this->requireLists()->update(
				$userId,
				$listId,
				$title,
				$repliesPolicy,
				$exclusive,
			));
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}
	}

	public function deleteList(string $userId, string $listId): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		try {
			$this->requireLists()->delete($userId, $listId);
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listAccounts(
		string $userId,
		string $listId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

		try {
			$memberIds = $this->requireLists()->memberIds($userId, $listId);
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}

		return $this->accountPage(
			$memberIds,
			$limit,
			$maxId,
			$sinceId,
			$minId,
		);
	}

	/**
	 * @param list<string> $accountIds
	 */
	public function addListAccounts(string $userId, string $listId, array $accountIds): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		foreach ($accountIds as $accountId) {
			$this->requireLocalAccount($accountId);
		}

		try {
			$this->requireLists()->addMembers($userId, $listId, $accountIds);
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}
	}

	/**
	 * @param list<string> $accountIds
	 */
	public function removeListAccounts(string $userId, string $listId, array $accountIds): void
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		foreach ($accountIds as $accountId) {
			$this->requireLocalAccount($accountId);
		}

		try {
			$this->requireLists()->removeMembers($userId, $listId, $accountIds);
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}
	}

	/**
	 * The caller's private list timeline.
	 *
	 * A list only contains local accounts in this slice. Its timeline merges
	 * member posts and boosts, applies the same mute/block and visibility
	 * rules as the home timeline, and filters replies according to the list's
	 * `replies_policy`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listTimeline(
		string $userId,
		string $listId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$list = $this->ownedList($userId, $listId);
		[$before, $after] = $this->listCursor($maxId, $sinceId ?? $minId);

		return $this->listStatuses(
			$this->listEntries($list, $userId, $this->clamp($limit), $before, $after),
			$userId,
		);
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
		?string $declaredMimeType = null,
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
				declaredMimeType: $declaredMimeType,
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
		bool $hasFocus = false,
		?float $focusX = null,
		?float $focusY = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$media = $this->requireMedia();

		$attachment = $this->ownedUnattachedMedia($media, $userId, $mediaId);
		if ($hasDescription) {
			$attachment = $media->updateAltText($attachment, $description);
		}

		if ($hasFocus) {
			$attachment = $media->updateFocus($attachment, $focusX, $focusY);
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
		if ($this->isRemoteActorTarget($targetId)) {
			$existing = $this->followingRepository?->find($userId, $targetId);
			if ($existing !== null && $existing->state !== FollowState::Rejected) {
				return $this->remoteRelationship($userId, $targetId);
			}

			$actor = $this->fetchRemoteActor($targetId);
			$following = $this->federatedFollowing ?? throw new MastodonRequestException(
				503,
				'follows_not_configured',
				'Remote following is not configured.',
			);

			try {
				$following->follow($userId, $actor->actorId, $actor->inboxUrl);
			} catch (FollowingBlockedException $exception) {
				throw new MastodonRequestException(403, 'blocked_actor', $exception->getMessage());
			} catch (FollowingNotConfiguredException $exception) {
				throw new MastodonRequestException(503, 'follows_not_configured', $exception->getMessage());
			} catch (DeliveryFailedException|HttpClientException $exception) {
				throw new MastodonRequestException(502, 'delivery_failed', 'The remote inbox did not accept the Follow.');
			} catch (ValidationException $exception) {
				throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
			}

			return $this->remoteRelationship($userId, $actor->actorId);
		}

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
		if ($this->isRemoteActorTarget($targetId)) {
			$following = $this->federatedFollowing ?? throw new MastodonRequestException(
				503,
				'follows_not_configured',
				'Remote following is not configured.',
			);
			try {
				$following->unfollow($userId, $targetId);
			} catch (FollowingNotConfiguredException $exception) {
				throw new MastodonRequestException(503, 'follows_not_configured', $exception->getMessage());
			} catch (DeliveryFailedException|HttpClientException) {
				throw new MastodonRequestException(502, 'delivery_failed', 'The remote inbox did not accept the Unfollow.');
			}

			return $this->remoteRelationship($userId, $targetId);
		}

		$this->requireLocalAccount($targetId);
		$this->requireRelationships()->unfollow($userId, $targetId);

		return $this->requireRelationships()->relationship($userId, $targetId);
	}

	/**
	 * Mutes a local account or a remote actor URL and returns the relationship.
	 *
	 * The mute hides the target from the caller's home timeline. With
	 * `notifications` it also hides events caused by the target from the
	 * notification list and unread count. Repeating the call updates the same
	 * row; a duration of null means indefinite.
	 *
	 * @return array<string, mixed>
	 */
	public function muteAccount(
		string $userId,
		string $targetId,
		bool $notifications = false,
		?int $duration = null,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$targetId = trim($targetId);
		$this->requireMuteTarget($targetId);

		if ($targetId === $userId) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'You cannot mute yourself.',
			);
		}

		try {
			$this->requireMutes()->mute($userId, $targetId, $notifications, $duration);
		} catch (InvalidArgumentException $exception) {
			throw new MastodonRequestException(422, 'validation_failed', $exception->getMessage());
		}

		return $this->muteRelationship($userId, $targetId);
	}

	/**
	 * Removes a mute and returns the resulting relationship.
	 *
	 * Removing an absent or expired mute is a successful no-op, matching the
	 * idempotent Mastodon unmute response.
	 *
	 * @return array<string, mixed>
	 */
	public function unmuteAccount(string $userId, string $targetId): array
	{
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$targetId = trim($targetId);
		$this->requireMuteTarget($targetId);
		$this->requireMutes()->unmute($userId, $targetId);

		return $this->muteRelationship($userId, $targetId);
	}

	/**
	 * The caller's active mutes as Mastodon accounts, newest first.
	 *
	 * The cursor is the account id of a mute; because Mastodon has no mute
	 * entity id, the account id is the only stable cursor a client can send.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function mutes(
		string $userId,
		int $limit,
		?string $maxId,
		?string $sinceId,
		?string $minId,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);
		$mutes = $this->requireMutes();
		[$before, $beforeActorId, $after, $afterActorId] = $this->muteCursor(
			$mutes,
			$userId,
			$maxId,
			$sinceId ?? $minId,
		);

		return array_map(
			fn (Mute $mute): array => $this->presenter->actorAccount($mute->actorId),
			$mutes->forUser(
				$userId,
				$this->clampAccountList($limit),
				$before,
				$beforeActorId,
				$after,
				$afterActorId,
			),
		);
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
		return array_map(function (string $targetId) use ($userId): array {
			if ($this->isRemoteActorTarget($targetId)) {
				return $this->remoteRelationship($userId, $targetId);
			}

			$this->requireLocalAccount($targetId);
			return $this->requireRelationships()->relationship($userId, $targetId);
		}, $targetIds);
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
				$this->mutes?->notificationActorIds($userId) ?? [],
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
			if ($status === null && $notification->postId !== null && $this->remotePosts !== null) {
				$remote = $this->remotePosts->findPostByObjectId($notification->postId);
				if ($remote !== null && $remote->deletedAt === null) {
					try {
						$remote = $this->remotePost($userId, $remote->objectId);
						$interactions = $this->reactions?->remoteInteractionsFor($userId, $remote);
						$status = $this->presenter->remoteStatus(
							$remote,
							($interactions['viewer']['favourited'] ?? false) === true,
							($interactions['viewer']['reblogged'] ?? false) === true,
						);
					} catch (MastodonRequestException) {
						// A notification never grants access to a private or unfollowed
						// remote status; keep the event but omit its status projection.
					}
				}
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
				$this->mutes?->notificationActorIds($userId) ?? [],
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
		bool $resolve = false,
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

		$remoteHandle = $resolve && $offset === 0 && ($type === null || $type === 'accounts')
			? RemoteHandle::tryFrom($query)
			: null;
		$resolvedRemoteAccount = null;
		if ($remoteHandle !== null) {
			if ($this->remoteAccountSearch === null) {
				throw new MastodonRequestException(
					503,
					'search_not_configured',
					'Remote account search is not configured.',
				);
			}

			try {
				$profile = $this->remoteAccountSearch->lookup($remoteHandle);
			} catch (RemoteSearchException) {
				throw new MastodonRequestException(
					503,
					'remote_search_failed',
					'Remote account lookup failed.',
				);
			}

			if ($profile !== null
				&& (!$following || $this->followingRepository?->find($userId, $profile->actorId)?->state === FollowState::Accepted)
				&& $this->blocks?->findByActor($userId, $profile->actorId) === null
				&& $this->inboundBlocks?->isBlocked($userId, $profile->actorId) !== true
			) {
				$resolvedRemoteAccount = $this->presenter->remoteAccount($profile);
			}
		}

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
		$hashtags = [];

		if (($type === null || $type === 'hashtags') && $this->trends !== null) {
			$since = $this->now()->modify('-7 days');
			foreach ($this->trends->top($since, $limit, $query) as $trend) {
				$hashtags[] = [
					'name' => $trend->hashtag,
					'url' => $this->baseUrl() . '/tags/' . rawurlencode($trend->hashtag),
					'history' => [],
				];
			}
		}

		if ($type === null || $type === 'accounts') {
			if ($this->searchActors === null && $resolvedRemoteAccount === null) {
				throw new MastodonRequestException(
					503,
					'search_not_configured',
					'Local account search is not configured.',
				);
			}

			$localAccounts = $this->searchActors === null
				? []
				: array_map(
					fn (string $accountId): array => $this->presenter->account($accountId),
					$this->searchActors->search($query, $limit, $offset, $allowedAuthors),
				);
			$accounts = $resolvedRemoteAccount !== null && $offset === 0
				? array_slice([$resolvedRemoteAccount, ...$localAccounts], 0, $limit)
				: array_slice($localAccounts, 0, $limit);
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
			'hashtags' => $hashtags,
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

	private function isRemotePollId(string $pollId): bool
	{
		return filter_var($pollId, FILTER_VALIDATE_URL) !== false
			&& in_array(strtolower((string) parse_url($pollId, PHP_URL_SCHEME)), ['http', 'https'], true);
	}

	private function requireRemotePolls(): RemotePollService
	{
		if ($this->remotePolls === null) {
			throw new MastodonRequestException(503, 'polls_not_configured', 'Remote poll storage is not configured.');
		}

		return $this->remotePolls;
	}

	private function requireRemotePollAccess(string $pollId, ?string $viewerId): void
	{
		$polls = $this->requireRemotePolls();
		$poll = $polls->find($pollId);
		$follow = $viewerId === null ? null : $this->followingRepository?->find($viewerId, $poll->actorId);
		if ($follow?->state !== FollowState::Accepted) {
			// Remote Question rows do not retain their original audience. Only
			// expose them through this compatibility endpoint to accepted followers.
			throw new MastodonRequestException(404, 'not_found', 'Poll not found.');
		}
	}

	/** @param array<string, mixed> $poll */
	private function remoteMastodonPoll(array $poll): array
	{
		return [
			'id' => $poll['object_id'],
			'expires_at' => $poll['ends_at'],
			'expired' => $poll['closed'],
			'multiple' => $poll['multiple'],
			'votes_count' => $poll['votes_count'],
			'voters_count' => $poll['voters_count'],
			'voted' => $poll['voted'],
			'own_votes' => $poll['own_votes'],
			'options' => $poll['options'],
			'emojis' => [],
		];
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

	private function requireLists(): AccountListService
	{
		if ($this->lists === null) {
			throw new MastodonRequestException(
				503,
				'lists_not_configured',
				'List storage is not configured.',
			);
		}

		return $this->lists;
	}

	private function ownedList(string $userId, string $listId): AccountList
	{
		try {
			return $this->requireLists()->find($userId, $listId);
		} catch (AccountListNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'List not found.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listEntity(AccountList $list): array
	{
		return $list->toArray();
	}

	/**
	 * @return list<TimelineEntry>
	 */
	private function listEntries(
		AccountList $list,
		string $viewerId,
		int $limit,
		?DateTimeImmutable $before,
		?DateTimeImmutable $after,
	): array {
		$members = $this->requireLists()->memberIds($viewerId, $list->id);
		if ($members === []) {
			return [];
		}

		$memberSet = array_fill_keys($members, true);
		$muted = array_fill_keys($this->mutes?->actorIds($viewerId) ?? [], true);
		$blocked = array_fill_keys($this->relationships?->blockedAccountIds($viewerId) ?? [], true);
		$following = $this->relationships === null
			? []
			: array_fill_keys($this->relationships->localFollowingUserIds($viewerId), true);
		$scan = max(100, $limit * 4);
		$entries = [];
		$parents = [];

		foreach ($this->posts->timelineForAuthors($members, $scan, $before) as $post) {
			if ($post->visibility === Visibility::Direct
				|| ($post->visibility === Visibility::Followers && !isset($following[$post->authorId]))
			) {
				continue;
			}

			if (isset($muted[$post->authorId]) || isset($blocked[$post->authorId])) {
				continue;
			}

			if (!$this->includeListReply($post, $list, $viewerId, $memberSet, $following, $parents)) {
				continue;
			}

			if ($after !== null && $post->publishedAt <= $after) {
				continue;
			}

			$entries[] = TimelineEntry::fromPost($post);
		}

		foreach ($this->boosts?->timelineForUsers($members, $scan, $before) ?? [] as $boost) {
			$post = $this->posts->find($boost->postId);
			if ($post === null || $post->isDeleted() || !$post->visibility->isPubliclyVisible()) {
				continue;
			}

			if (isset($muted[$boost->userId]) || isset($blocked[$boost->userId])) {
				continue;
			}

			if (isset($muted[$post->authorId]) || isset($blocked[$post->authorId])) {
				continue;
			}

			if (!$this->includeListReply($post, $list, $viewerId, $memberSet, $following, $parents)) {
				continue;
			}

			if ($after !== null && $boost->createdAt <= $after) {
				continue;
			}

			$entries[] = new TimelineEntry(
				id: $boost->id,
				authorId: $boost->userId,
				objectId: $post->id,
				objectAuthorId: $post->authorId,
				content: $post->content,
				contentWarning: $post->contentWarning,
				publishedAt: $boost->createdAt,
				visibility: $post->visibility,
				boostedBy: $boost->userId,
			);
		}

		usort($entries, static function (TimelineEntry $a, TimelineEntry $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($entries, 0, max(1, $limit));
	}

	/**
	 * @param array<string, bool>       $members
	 * @param array<string, bool>       $following
	 * @param array<string, Post|null>  $parents
	 */
	private function includeListReply(
		Post $post,
		AccountList $list,
		string $viewerId,
		array $members,
		array $following,
		array &$parents,
	): bool {
		if ($post->inReplyToId === null) {
			return true;
		}

		if ($list->repliesPolicy === ListRepliesPolicy::None) {
			return false;
		}

		if (!array_key_exists($post->inReplyToId, $parents)) {
			$parents[$post->inReplyToId] = $this->posts->find($post->inReplyToId);
		}

		$parent = $parents[$post->inReplyToId];
		if ($parent === null || $parent->isDeleted()) {
			return false;
		}

		return match ($list->repliesPolicy) {
			ListRepliesPolicy::Members => isset($members[$parent->authorId]),
			ListRepliesPolicy::Followed => $parent->authorId === $viewerId
				|| isset($following[$parent->authorId]),
			ListRepliesPolicy::None => false,
		};
	}

	/**
	 * @param list<TimelineEntry> $entries
	 *
	 * @return list<array<string, mixed>>
	 */
	private function listStatuses(array $entries, string $viewerId): array
	{
		$statuses = [];
		foreach ($entries as $entry) {
			if ($entry->isBoost()) {
				$boost = $this->boosts?->find($entry->id);
				$post = $this->posts->find($entry->objectId);
				if ($boost === null || $post === null || $post->isDeleted()) {
					continue;
				}

				$statuses[] = $this->presenter->boostStatus($boost, $post, $viewerId);
				continue;
			}

			$post = $this->posts->find($entry->id);
			if ($post === null || $post->isDeleted()) {
				continue;
			}

			$statuses[] = $this->presenter->status($post, $viewerId);
		}

		return $statuses;
	}

	/**
	 * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
	 */
	private function listCursor(?string $maxId, ?string $sinceId): array
	{
		if ($maxId !== null && $maxId !== '' && $sinceId !== null && $sinceId !== '') {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'max_id and since_id/min_id cannot be combined.',
			);
		}

		return [
			$maxId === null || $maxId === '' ? null : $this->listCursorPoint($maxId),
			$sinceId === null || $sinceId === '' ? null : $this->listCursorPoint($sinceId),
		];
	}

	private function listCursorPoint(string $id): DateTimeImmutable
	{
		$post = $this->posts->find($id);
		if ($post !== null && !$post->isDeleted()) {
			return $post->publishedAt;
		}

		$boost = $this->boosts?->find($id);
		if ($boost !== null) {
			return $boost->createdAt;
		}

		throw new MastodonRequestException(404, 'not_found', 'Status cursor not found.');
	}

	private function isRemoteActorTarget(string $targetId): bool
	{
		if ($this->localActors?->has($targetId) === true) {
			return false;
		}

		$parts = parse_url($targetId);
		return is_array($parts)
			&& in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			&& isset($parts['host']);
	}

	private function isRemoteObjectTarget(string $targetId): bool
	{
		$parts = parse_url($targetId);

		return is_array($parts)
			&& in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			&& isset($parts['host']);
	}

	private function requireReactionService(): ReactionService
	{
		if ($this->reactions === null) {
			throw new MastodonRequestException(503, 'reactions_not_configured', 'Remote reactions are not configured.');
		}

		return $this->reactions;
	}

	private function remotePost(string $userId, string $objectId): RemotePost
	{
		try {
			return $this->requireReactionService()->remotePostFor($userId, $objectId);
		} catch (ReactionException $exception) {
			throw $this->mapRemoteReactionException($exception);
		}
	}

	private function mapRemoteReactionException(ReactionException $exception): MastodonRequestException
	{
		return match ($exception->reason) {
			'remote_post_not_found', 'remote_post_not_followed' => new MastodonRequestException(404, 'not_found', 'Status not found.'),
			'blocked_actor' => new MastodonRequestException(403, 'blocked_actor', $exception->getMessage()),
			default => new MastodonRequestException(422, $exception->reason, $exception->getMessage()),
		};
	}

	private function fetchRemoteActor(string $actorId): RemoteActor
	{
		if ($this->remoteActors === null) {
			throw new MastodonRequestException(503, 'remote_accounts_not_configured', 'Remote account resolution is not configured.');
		}

		try {
			$actor = $this->remoteActors->fetch($actorId);
		} catch (UnknownActorException) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}

		if ($actor->actorId !== $actorId) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}

		return $actor;
	}

	/** @return array<string, mixed> */
	private function remoteRelationship(string $userId, string $actorId): array
	{
		$followed = $this->followingRepository?->find($userId, $actorId);
		$state = $followed?->state;
		$blocking = $this->blocks?->findByActor($userId, $actorId) !== null;
		$blockedBy = $this->inboundBlocks?->isBlocked($userId, $actorId) ?? false;

		return [
			'id' => $actorId,
			'following' => $state === FollowState::Accepted,
			'showing_reblogs' => true,
			'notifying' => false,
			'languages' => [],
			'followed_by' => false,
			'blocking' => $blocking,
			'blocked_by' => $blockedBy,
			'muting' => false,
			'muting_notifications' => false,
			'requested' => $state === FollowState::Pending,
			'domain_blocking' => false,
			'endorsed' => false,
			'note' => '',
		];
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

	private function requireMutes(): MuteService
	{
		if ($this->mutes === null) {
			throw new MastodonRequestException(
				503,
				'mutes_not_configured',
				'Mute storage is not configured.',
			);
		}

		return $this->mutes;
	}

	/**
	 * Accepts a local account id or an absolute remote actor URL.
	 *
	 * With no local directory the host has opted out of distinguishing local
	 * from remote ids, so any non-empty id is accepted, matching the account
	 * endpoints. Otherwise an unknown bare id is a 404 and a malformed URL is
	 * rejected instead of being stored as an unusable target.
	 */
	private function requireMuteTarget(string $targetId): void
	{
		if ($targetId === '' || mb_strlen($targetId) > Mute::MAX_ACTOR_LENGTH) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}

		if ($this->localActors?->has($targetId) === true || $this->isRemoteActorId($targetId)) {
			return;
		}

		if ($this->localActors !== null) {
			throw new MastodonRequestException(404, 'not_found', 'Account not found.');
		}
	}

	private function isRemoteActorId(string $targetId): bool
	{
		$parts = parse_url($targetId);

		return is_array($parts)
			&& isset($parts['scheme'], $parts['host'])
			&& in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			&& (string) $parts['host'] !== '';
	}

	/**
	 * A local target uses the full relationship projection; a remote target is
	 * not in the local follow graph, so its relationship is the documented
	 * defaults with the mute flags filled in.
	 *
	 * @return array<string, mixed>
	 */
	private function muteRelationship(string $userId, string $targetId): array
	{
		if ($this->localActors?->has($targetId) === true) {
			return $this->requireRelationships()->relationship($userId, $targetId);
		}

		$mute = $this->requireMutes()->find($userId, $targetId);

		return [
			'id' => $targetId,
			'following' => false,
			'showing_reblogs' => true,
			'notifying' => false,
			'languages' => [],
			'followed_by' => false,
			'blocking' => false,
			'blocked_by' => false,
			'muting' => $mute !== null,
			'muting_notifications' => $mute?->notifications ?? false,
			'requested' => false,
			'domain_blocking' => false,
			'endorsed' => false,
			'note' => '',
		];
	}

	/**
	 * Resolves one account-id cursor to the caller's mute timestamp.
	 *
	 * @return array{0: ?\DateTimeImmutable, 1: ?string, 2: ?\DateTimeImmutable, 3: ?string}
	 */
	private function muteCursor(
		MuteService $mutes,
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
		$beforeActorId = null;
		if ($maxId !== null && $maxId !== '') {
			$mute = $this->muteCursorMute($mutes, $userId, $maxId);
			$before = $mute->createdAt;
			$beforeActorId = $mute->actorId;
		}

		$after = null;
		$afterActorId = null;
		if ($sinceId !== null && $sinceId !== '') {
			$mute = $this->muteCursorMute($mutes, $userId, $sinceId);
			$after = $mute->createdAt;
			$afterActorId = $mute->actorId;
		}

		return [$before, $beforeActorId, $after, $afterActorId];
	}

	private function muteCursorMute(MuteService $mutes, string $userId, string $actorId): Mute
	{
		$mute = $mutes->find($userId, $actorId);
		if ($mute === null) {
			throw new MastodonRequestException(404, 'not_found', 'Mute cursor not found.');
		}

		return $mute;
	}

	private function requireBookmarks(): BookmarkRepository
	{
		if ($this->bookmarks === null) {
			throw new MastodonRequestException(
				503,
				'bookmarks_not_configured',
				'Bookmark storage is not configured.',
			);
		}

		return $this->bookmarks;
	}

	/**
	 * Resolves one status-id cursor to the caller's bookmark timestamp.
	 *
	 * @return array{0: ?\DateTimeImmutable, 1: ?string, 2: ?\DateTimeImmutable, 3: ?string}
	 */
	private function bookmarkCursor(
		BookmarkRepository $bookmarks,
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
		$beforePostId = null;
		if ($maxId !== null && $maxId !== '') {
			$bookmark = $this->bookmarkCursorBookmark($bookmarks, $userId, $maxId);
			$before = $bookmark->createdAt;
			$beforePostId = $bookmark->postId;
		}

		$after = null;
		$afterPostId = null;
		if ($sinceId !== null && $sinceId !== '') {
			$bookmark = $this->bookmarkCursorBookmark($bookmarks, $userId, $sinceId);
			$after = $bookmark->createdAt;
			$afterPostId = $bookmark->postId;
		}

		return [$before, $beforePostId, $after, $afterPostId];
	}

	private function bookmarkCursorBookmark(
		BookmarkRepository $bookmarks,
		string $userId,
		string $postId,
	): Bookmark {
		$bookmark = $bookmarks->find($userId, $postId);
		if ($bookmark === null) {
			throw new MastodonRequestException(404, 'not_found', 'Status cursor not found.');
		}

		return $bookmark;
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
		if ($post === null || $post->isDeleted() || !$post->visibility->mayListPublicly()) {
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
