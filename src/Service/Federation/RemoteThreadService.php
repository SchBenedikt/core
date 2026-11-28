<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\RemoteObjectTombstoneStore;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThread;
use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use Psr\Log\LoggerInterface;

use function array_fill_keys;
use function array_reverse;
use function count;
use function trim;

/**
 * Builds a bounded context view around one stored remote post.
 *
 * The target must be a live remote post from an actor the reader follows and
 * does not block. Ancestors and direct replies are fetched on demand, cached
 * by object id, and filtered against the reader's blocks. A failure to fetch
 * one parent/reply is logged and leaves the rest of the view usable; a failure
 * to fetch the target itself is reported to the caller.
 */
final class RemoteThreadService
{
	public function __construct(
		private readonly RemotePostRepository $remotePosts,
		private readonly RemoteThreadRepository $threads,
		private readonly FollowingRepository $following,
		private readonly BlockRepository $blocks,
		private readonly RemoteThreadFetcher $fetcher,
		private readonly LoggerInterface $logger,
		private readonly ?RemoteObjectTombstoneStore $tombstones = null,
		private readonly ?Clock $clock = null,
	) {
	}

	/**
	 * @throws RemoteThreadNotFoundException when the target is not visible to this reader
	 * @throws RemoteThreadException         when the target object cannot be fetched or parsed
	 */
	public function threadForUser(string $activityId, string $localUserId): RemoteThread
	{
		$activityId = trim($activityId);
		$target = $this->remotePosts->findByActivityId($activityId);
		if ($target === null || $target->deletedAt !== null) {
			throw new RemoteThreadNotFoundException('The remote post was not found.');
		}

		$followed = $this->following->find($localUserId, $target->actorId) !== null;

		// A stored post with a verified public audience may be opened by any
		// signed-in reader; followers-only and unknown audiences still require
		// the follow edge.
		if (!$followed && $target->audience !== Visibility::Public) {
			throw new RemoteThreadNotFoundException('The remote post was not found.');
		}

		if ($this->blocks->findByActor($localUserId, $target->actorId) !== null) {
			throw new RemoteThreadNotFoundException('The remote post was not found.');
		}

		try {
			$fetched = $this->fetcher->fetch($target->objectId);
		} catch (RemoteThreadException $exception) {
			$this->logger->warning('The remote thread target could not be fetched.', [
				'reason' => $exception->reason,
				'activity_id' => $target->activityId,
				'error' => $exception->getMessage(),
			]);

			throw $exception;
		}

		if ($fetched->post->objectId !== $target->objectId) {
			throw new RemoteThreadException(
				'thread_invalid_document',
				'The fetched object does not match the requested remote post.',
			);
		}

		$this->remember($fetched->post);

		$blocked = array_fill_keys($this->blocks->actorIds($localUserId), true);
		$ancestors = $this->ancestors($fetched->post, $blocked, $followed);
		$replies = $this->replies($target->objectId, $fetched, $blocked, $followed);

		return new RemoteThread($fetched->post, array_reverse($ancestors), $replies);
	}

	/**
	 * @param array<string, true> $blocked
	 *
	 * @return list<RemoteThreadPost> ordered parent first, which the caller reverses
	 */
	private function ancestors(RemoteThreadPost $target, array $blocked, bool $followed): array
	{
		$ancestors = [];
		$seen = [$target->objectId => true];
		$parentId = $target->inReplyToId;

		for ($depth = 0; $parentId !== null && $depth < $this->fetcher->maxDepth(); ++$depth) {
			if (isset($seen[$parentId])) {
				break;
			}

			$seen[$parentId] = true;
			$parent = $this->threads->find($parentId);

			if ($parent === null) {
				try {
					$parent = $this->fetcher->fetch($parentId)->post;
				} catch (RemoteThreadException $exception) {
					$this->logger->warning('Skipping a remote thread ancestor.', [
						'reason' => $exception->reason,
						'object_id' => $parentId,
						'error' => $exception->getMessage(),
					]);

					break;
				}

				$this->remember($parent);
			}

			if ($this->isTombstoned($parent)) {
				break;
			}

			if (!$followed && $parent->audience !== Visibility::Public) {
				// A reader without the follow edge only sees verified public
				// context; anything else ends the visible chain.
				break;
			}

			if (isset($blocked[$parent->authorId])) {
				break;
			}

			$ancestors[] = $parent;
			$parentId = $parent->inReplyToId;
		}

		return $ancestors;
	}

	/**
	 * @param array<string, true> $blocked
	 *
	 * @return list<RemoteThreadPost>
	 */
	private function replies(
		string $targetObjectId,
		RemoteThreadFetchResult $fetched,
		array $blocked,
		bool $followed,
	): array {
		if ($this->fetcher->maxReplies() === 0) {
			return [];
		}

		$candidates = $fetched->replies;
		$fetchFailed = false;

		if ($fetched->repliesUrl !== null) {
			try {
				$candidates = $this->fetcher->fetchReplies($fetched->repliesUrl);
			} catch (RemoteThreadException $exception) {
				$fetchFailed = true;
				$this->logger->warning('Remote thread replies could not be fetched.', [
					'reason' => $exception->reason,
					'url' => $fetched->repliesUrl,
					'error' => $exception->getMessage(),
				]);
			}
		}

		if ($fetchFailed) {
			$candidates = $this->threads->children($targetObjectId, $this->fetcher->maxReplies());
		}

		$replies = [];
		$seen = [$targetObjectId => true];
		foreach ($candidates as $candidate) {
			if (count($replies) >= $this->fetcher->maxReplies()) {
				break;
			}

			if ($candidate->inReplyToId !== $targetObjectId
				|| isset($seen[$candidate->objectId])
				|| isset($blocked[$candidate->authorId])
				|| (!$followed && $candidate->audience !== Visibility::Public)
				|| $this->isTombstoned($candidate)
			) {
				continue;
			}

			$seen[$candidate->objectId] = true;
			$this->remember($candidate);
			$replies[] = $candidate;
		}

		return $replies;
	}

	/**
	 * A fetched node its author deleted must not reappear as thread context:
	 * the origin may still serve the object, but the verified tombstone says
	 * it is gone.
	 */
	private function isTombstoned(RemoteThreadPost $post): bool
	{
		if ($this->tombstones === null) {
			return false;
		}

		return $this->tombstones->isDeleted(
			$post->authorId,
			$post->objectId,
			($this->clock ?? new SystemClock())->now(),
		);
	}

	private function remember(RemoteThreadPost $post): void
	{
		try {
			$this->threads->upsert($post);
		} catch (\Throwable $exception) {
			// The fetched view remains useful even when the cache write fails;
			// the next request will retry the idempotent upsert.
			$this->logger->error('Remote thread context could not be cached.', [
				'reason' => 'thread_store_failed',
				'object_id' => $post->objectId,
				'error' => $exception->getMessage(),
			]);
		}
	}
}
