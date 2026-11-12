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
use AlohaSocial\Core\Domain\Post\Favourite;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
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
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * The local, disabled-by-default Mastodon compatibility surface.
 *
 * It reuses the existing post service for writes and the existing repositories
 * for reads. There is no second status table: a Mastodon status is a core
 * post with an API-shaped projection. Remote posts, boosts and streaming are
 * intentionally outside this local slice. Favourites, follow edges and
 * notifications are local bookkeeping; notifications are recorded from local
 * favourites/follows and verified inbound Follows, and read from the
 * notification repository without a second account or post model.
 */
final class MastodonService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_SEARCH_OFFSET = 1000;

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
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($accountId);
		[$before, $beforeId, $after, $afterId] = $this->cursor($maxId, $sinceId ?? $minId);

		$posts = $this->posts->authorTimeline(
			$accountId,
			$this->clamp($limit),
			$before,
			$beforeId,
			$after,
			$afterId,
		);

		return $this->presenter->statuses($posts);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(string $postId, ?string $viewerId): array
	{
		$this->requireEnabled();

		return $this->presenter->status($this->visiblePost($postId, $viewerId), $viewerId);
	}

	/**
	 * @param list<string> $mediaIds
	 *
	 * @return array<string, mixed>
	 */
	public function createStatus(
		string $userId,
		string $content,
		Visibility $visibility,
		?string $spoilerText,
		array $mediaIds,
		?string $idempotencyKey,
	): array {
		$this->requireEnabled();
		$this->requireLocalAccount($userId);

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

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision(($this->clock ?? new SystemClock())->now());
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
