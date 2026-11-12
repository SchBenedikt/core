<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;

/**
 * The local, disabled-by-default Mastodon compatibility surface.
 *
 * It reuses the existing post service for writes and the existing repositories
 * for reads. There is no second status table: a Mastodon status is a core
 * post with an API-shaped projection. Remote posts, favourites, boosts,
 * notifications and streaming are intentionally outside this local slice.
 */
final class MastodonService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly Config $config,
		private readonly PostRepository $posts,
		private readonly PostService $postService,
		private readonly MastodonPresenter $presenter,
		private readonly ?LocalActorDirectory $localActors = null,
		private readonly ?MediaService $media = null,
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

		try {
			$post = $this->postService->getVisible($postId, $viewerId);
		} catch (PostNotFoundException) {
			throw new MastodonRequestException(404, 'not_found', 'Status not found.');
		}

		return $this->presenter->status($post);
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

		return $this->presenter->status($post);
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

		if ($this->media === null) {
			throw new MastodonRequestException(
				503,
				'media_not_configured',
				'Media storage is not configured.',
			);
		}

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
