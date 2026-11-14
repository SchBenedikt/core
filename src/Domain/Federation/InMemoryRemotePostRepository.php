<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

/**
 * Remote-post storage in memory, for tests and the reference assembly.
 *
 * It mirrors the SQL adapter's ordering, unique activity id and soft-delete
 * rules; the shared contract test is what keeps the two interchangeable.
 */
final class InMemoryRemotePostRepository implements RemotePostRepository, RemoteDomainCount
{
	public const MAX_ATTACHMENTS = 10;

	/**
	 * @var array<string, RemotePost>
	 */
	private array $posts = [];

	/**
	 * @var array<string, list<string>>
	 */
	private array $attachments = [];

	public function insert(RemotePost $post): void
	{
		if (isset($this->posts[$post->activityId])) {
			throw new DatabaseException('The remote activity id already exists.');
		}

		$this->posts[$post->activityId] = $post;
	}

	public function findByActivityId(string $activityId): ?RemotePost
	{
		return $this->posts[$activityId] ?? null;
	}

	public function findPostByObjectId(string $objectId): ?RemotePost
	{
		foreach ($this->posts as $post) {
			if ($post->kind === RemotePostKind::Post
				&& $post->objectId === $objectId
				&& $post->deletedAt === null
			) {
				return $post;
			}
		}

		return null;
	}

	public function updatePostContent(
		string $objectId,
		string $actorId,
		string $content,
		?string $contentWarning,
		DateTimeImmutable $updatedAt,
	): bool {
		foreach ($this->posts as $activityId => $post) {
			if ($post->kind !== RemotePostKind::Post
				|| $post->objectId !== $objectId
				|| $post->actorId !== $actorId
				|| $post->deletedAt !== null
			) {
				continue;
			}

			$this->posts[$activityId] = new RemotePost(
				activityId: $post->activityId,
				objectId: $post->objectId,
				actorId: $post->actorId,
				kind: $post->kind,
				content: $content,
				contentWarning: $contentWarning,
				publishedAt: $post->publishedAt,
				objectAuthorId: $post->objectAuthorId,
				deletedAt: $post->deletedAt,
				updatedAt: $updatedAt,
				objectType: $post->objectType,
				metadata: $post->metadata,
				quotedObjectId: $post->quotedObjectId,
			);

			return true;
		}

		return false;
	}

	public function softDeleteByActivityId(
		string $activityId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		$post = $this->posts[$activityId] ?? null;
		if ($post === null || $post->actorId !== $actorId || $post->deletedAt !== null) {
			return false;
		}

		$this->posts[$activityId] = $this->deleted($post, $deletedAt);

		return true;
	}

	public function softDeletePostByObjectId(
		string $objectId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		foreach ($this->posts as $activityId => $post) {
			if ($post->kind === RemotePostKind::Post
				&& $post->objectId === $objectId
				&& $post->actorId === $actorId
				&& $post->deletedAt === null
			) {
				$this->posts[$activityId] = $this->deleted($post, $deletedAt);

				return true;
			}
		}

		return false;
	}

	public function timelineForActors(
		array $actorIds,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		$wanted = array_fill_keys($actorIds, true);
		$posts = array_values(array_filter(
			$this->posts,
			static fn (RemotePost $post): bool => $post->deletedAt === null
				&& isset($wanted[$post->actorId])
				&& ($before === null || $post->publishedAt < $before),
		));

		usort($posts, static function (RemotePost $a, RemotePost $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->activityId, $a->activityId);
		});

		return array_slice($posts, 0, max(1, $limit));
	}

	public function linkAttachments(string $activityId, array $attachmentIds): void
	{
		$attachmentIds = array_values(array_unique($attachmentIds));
		if (count($attachmentIds) > self::MAX_ATTACHMENTS) {
			throw new DatabaseException('Too many attachments for one remote post.');
		}

		if ($attachmentIds === []) {
			unset($this->attachments[$activityId]);

			return;
		}

		$this->attachments[$activityId] = $attachmentIds;
	}

	public function remoteDomainCount(): int
	{
		$domains = [];
		foreach ($this->posts as $post) {
			if ($post->deletedAt !== null) {
				continue;
			}

			$host = parse_url($post->actorId, PHP_URL_HOST);
			if (is_string($host) && $host !== '') {
				$domains[strtolower($host)] = true;
			}
		}

		return count($domains);
	}

	public function isAttachmentLinked(string $attachmentId): bool
	{
		foreach ($this->attachments as $attachmentIds) {
			if (in_array($attachmentId, $attachmentIds, true)) {
				return true;
			}
		}

		return false;
	}

	public function attachmentIdsFor(array $activityIds): array
	{
		$result = [];
		foreach (array_unique($activityIds) as $activityId) {
			if (isset($this->attachments[$activityId])) {
				$result[$activityId] = $this->attachments[$activityId];
			}
		}

		return $result;
	}

	private function deleted(RemotePost $post, DateTimeImmutable $deletedAt): RemotePost
	{
		return new RemotePost(
			$post->activityId,
			$post->objectId,
			$post->actorId,
			$post->kind,
			$post->content,
			$post->contentWarning,
			$post->publishedAt,
			$post->objectAuthorId,
			$deletedAt,
			updatedAt: $post->updatedAt,
			objectType: $post->objectType,
			metadata: $post->metadata,
			quotedObjectId: $post->quotedObjectId,
		);
	}
}
