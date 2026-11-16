<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Post\Boost;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use Throwable;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function is_string;

/**
 * Sharing and unsharing a visible local post.
 *
 * Only public and unlisted posts can be boosted: a narrower post is not
 * addressed to the booster's followers, so announcing it would create a new
 * audience. The local row is committed before any delivery job is queued, and
 * a queue or configuration failure never rolls the local action back.
 */
final class BoostService
{
	public function __construct(
		private readonly PostService $posts,
		private readonly BoostRepository $boosts,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?JobQueue $queue = null,
		private readonly ?ActivityFactory $activities = null,
		private readonly ?InboxResolver $inboxes = null,
		private readonly ?NotificationService $notifications = null,
	) {
	}

	/**
	 * @throws \AlohaSocial\Core\Domain\Post\PostNotFoundException when the post is not visible
	 */
	public function boost(string $userId, string $postId): BoostResult
	{
		$post = $this->posts->getVisible($postId, $userId);
		if (!in_array($post->visibility, [Visibility::Public, Visibility::Unlisted], true)) {
			throw new ValidationException([
				'visibility' => 'Only public and unlisted posts can be boosted.',
			]);
		}

		$existing = $this->boosts->findByUserAndPost($userId, $post->id);
		if ($existing !== null) {
			return new BoostResult($existing, false);
		}

		$boost = new Boost(
			id: 'boost-' . $this->ids->generate(),
			userId: $userId,
			postId: $post->id,
			createdAt: Time::toSecondPrecision($this->clock->now()),
		);

		try {
			$this->boosts->insert($boost);
		} catch (DatabaseException $exception) {
			// The unique (user, post) index is the authority. Two concurrent
			// taps of the same button answer with the stored winner.
			$winner = $this->boosts->findByUserAndPost($userId, $post->id);
			if ($winner !== null) {
				return new BoostResult($winner, false);
			}

			throw $exception;
		}

		$this->notifications?->recordReblog($post->authorId, $userId, $post->id);
		$this->queueActivity($boost, false);

		return new BoostResult($boost, true);
	}

	/**
	 * @return bool whether a local boost was removed
	 */
	public function unboost(string $userId, string $postId): bool
	{
		$boost = $this->boosts->findByUserAndPost($userId, $postId);
		if ($boost === null || !$this->boosts->remove($userId, $postId)) {
			return false;
		}

		$this->queueActivity($boost, true);

		return true;
	}

	private function queueActivity(Boost $boost, bool $undo): void
	{
		if ($this->queue === null || $this->activities === null || $this->inboxes === null) {
			return;
		}

		try {
			$inboxes = array_values(array_unique(array_filter(
				$this->inboxes->inboxesFor($boost->userId),
				static fn (mixed $inbox): bool => is_string($inbox) && trim($inbox) !== '',
			)));
		} catch (Throwable) {
			// The boost row is already committed; a failing follower lookup
			// must not turn the successful local action into a 500.
			return;
		}
		if ($inboxes === []) {
			return;
		}

		try {
			$activity = $undo
				? $this->activities->undoAnnounce($boost)
				: $this->activities->announce($boost);
			$keyId = $this->activities->keyId($boost->userId);
		} catch (ConfigurationException) {
			return;
		}

		foreach ($inboxes as $inbox) {
			try {
				$this->queue->push(JobType::FEDERATION_DELIVER, [
					'inbox' => $inbox,
					'activity' => $activity,
					'key_id' => $keyId,
				], subject: JobSubject::account($boost->userId));
			} catch (Throwable) {
				// The local boost is committed; the host queue can retry from
				// its own dead-letter handling, but must not fail the request.
			}
		}
	}
}
