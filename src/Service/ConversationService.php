<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Conversation\ConversationMarker;
use AlohaSocial\Core\Domain\Conversation\ConversationMarkerRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;

/** Per-account thread roots, unread state and dismiss-until-new-message behavior. */
final class ConversationService
{
	private const MAX_ROOT_DEPTH = 256;

	/** @var array<string, string> */
	private array $rootCache = [];

	public function __construct(
		private readonly PostService $postService,
		private readonly PostRepository $posts,
		private readonly ConversationMarkerRepository $markers,
	) {
	}

	/**
	 * The private state attached to each live direct post in a timeline page.
	 *
	 * @param list<Post> $posts
	 * @return array<string, array{conversation_id:string, unread:bool, dismissed:bool}>
	 */
	public function metadata(string $userId, array $posts): array
	{
		$result = [];
		$markers = [];
		foreach ($posts as $post) {
			if ($post->visibility !== Visibility::Direct) {
				continue;
			}
			$rootId = $this->rootId($post);
			if (!array_key_exists($rootId, $markers)) {
				$markers[$rootId] = $this->markers->find($userId, $rootId);
			}
			$marker = $markers[$rootId];
			$read = $marker !== null && $this->isAtOrBefore(
				$post,
				$marker->readThroughAt,
				$marker->readThroughPostId,
			);
			$dismissed = $marker !== null && $this->isAtOrBefore(
				$post,
				$marker->dismissedThroughAt,
				$marker->dismissedThroughPostId,
			);
			$result[$post->id] = [
				'conversation_id' => $rootId,
				'unread' => $post->authorId !== $userId && !$read,
				'dismissed' => $dismissed,
			];
		}

		return $result;
	}

	/** Mark a visible direct post and all earlier messages in its thread as read. */
	public function markRead(string $userId, string $postId): string
	{
		return $this->markThrough($userId, $postId, true);
	}

	/** Hide the thread through this message; a later reply makes it visible again. */
	public function dismiss(string $userId, string $postId): string
	{
		return $this->markThrough($userId, $postId, false);
	}

	private function markThrough(string $userId, string $postId, bool $read): string
	{
		$post = $this->postService->getVisible($postId, $userId);
		if ($post->visibility !== Visibility::Direct) {
			throw new PostNotFoundException($postId);
		}
		$rootId = $this->rootId($post);
		$existing = $this->markers->find($userId, $rootId);
		$readAt = $existing?->readThroughAt;
		$readId = $existing?->readThroughPostId;
		$dismissedAt = $existing?->dismissedThroughAt;
		$dismissedId = $existing?->dismissedThroughPostId;
		if ($read) {
			if (!$this->isAtOrBefore($post, $readAt, $readId)) {
				$readAt = $post->publishedAt;
				$readId = $post->id;
			}
		} elseif (!$this->isAtOrBefore($post, $dismissedAt, $dismissedId)) {
			$dismissedAt = $post->publishedAt;
			$dismissedId = $post->id;
		}

		$this->markers->save(new ConversationMarker(
			id: ConversationMarker::idFor($userId, $rootId),
			userId: $userId,
			conversationId: $rootId,
			readThroughPostId: $readId,
			readThroughAt: $readAt,
			dismissedThroughPostId: $dismissedId,
			dismissedThroughAt: $dismissedAt,
		));

		return $rootId;
	}

	private function rootId(Post $post): string
	{
		if (isset($this->rootCache[$post->id])) {
			return $this->rootCache[$post->id];
		}

		$root = $post;
		$seen = [$post->id => true];
		for ($depth = 0; $root->inReplyToId !== null && $depth < self::MAX_ROOT_DEPTH; $depth++) {
			$parentId = $root->inReplyToId;
			if (isset($seen[$parentId])) {
				break;
			}
			$parent = $this->posts->find($parentId);
			if ($parent === null || $parent->visibility !== Visibility::Direct) {
				break;
			}
			$seen[$parentId] = true;
			$root = $parent;
		}

		$this->rootCache[$post->id] = $root->id;
		return $root->id;
	}

	private function isAtOrBefore(Post $post, ?\DateTimeImmutable $throughAt, ?string $throughId): bool
	{
		if ($throughAt === null || $throughId === null) {
			return false;
		}
		if ($post->publishedAt < $throughAt) {
			return true;
		}
		if ($post->publishedAt > $throughAt) {
			return false;
		}

		return strcmp($post->id, $throughId) <= 0;
	}
}
