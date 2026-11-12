<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublication;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Support\Time;

/**
 * Schedules and performs AT Protocol publication for local posts.
 *
 * The feature is opt-in. Scheduling only happens when the host supplied an
 * account resolver and a publisher, and it silently skips posts the AT
 * Protocol cannot represent. A manual publish is explicit and reports those
 * refusals as errors, so no partial record is ever written.
 */
final class AtprotoPublishService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'atproto.publish';

	public function __construct(
		private readonly Config $config,
		private readonly PostRepository $posts,
		private readonly AtprotoPublicationRepository $publications,
		private readonly ?AtprotoAccountResolver $accounts,
		private readonly ?AtprotoPublisher $publisher,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, false);
	}

	/**
	 * Queues a best-effort mirror after a post was created.
	 *
	 * Missing accounts and unsupported posts are normal for this hook; the
	 * post creation itself must not fail because AT Protocol is unavailable.
	 */
	public function schedule(Post $post): void
	{
		if (!$this->isEnabled() || !$this->isConfigured() || $this->rejectionReason($post) !== null) {
			return;
		}

		if ($this->accounts?->resolve($post->authorId) === null) {
			return;
		}

		$this->queue->push(JobType::ATPROTO_PUBLISH, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
		]);
	}

	/**
	 * Publishes one post immediately, or returns the existing publication.
	 *
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoPostRejectedException
	 * @throws PostNotFoundException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function publish(string $localUserId, string $postId): AtprotoPublication
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}

		if (!$this->isConfigured()) {
			throw new AtprotoNotConfiguredException();
		}

		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted() || $post->authorId !== $localUserId) {
			throw new PostNotFoundException($postId);
		}

		$rejection = $this->rejectionReason($post);
		if ($rejection !== null) {
			throw new AtprotoPostRejectedException($rejection);
		}

		$existing = $this->publications->findForPost($localUserId, $postId);
		if ($existing !== null) {
			return $existing;
		}

		$account = $this->accounts?->resolve($localUserId);
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		$rkey = Tid::forRecord($post->publishedAt, $post->id . '|' . $account->did);
		$uri = $this->publisher?->publish(
			$account,
			$rkey,
			AtprotoPostRecord::fromPost($post),
		);

		if ($uri === null) {
			throw new AtprotoNotConfiguredException();
		}

		$publication = new AtprotoPublication(
			localUserId: $localUserId,
			postId: $post->id,
			rkey: $rkey,
			uri: $uri,
			publishedAt: Time::toSecondPrecision($this->clock->now()),
		);

		try {
			$this->publications->insert($publication);
		} catch (DatabaseException $exception) {
			// A concurrent retry may have stored the same idempotency key
			// after the successful write. Return that record instead of
			// turning a successful publication into an error.
			$stored = $this->publications->findForPost($localUserId, $postId);
			if ($stored !== null) {
				return $stored;
			}

			throw $exception;
		}

		return $publication;
	}

	private function isConfigured(): bool
	{
		return $this->accounts !== null && $this->publisher !== null;
	}

	private function rejectionReason(Post $post): ?string
	{
		if ($post->visibility !== Visibility::Public) {
			return AtprotoPostRejectedException::NOT_PUBLIC;
		}

		if ($post->attachmentIds !== []) {
			return AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED;
		}

		if (!AtprotoPostRecord::acceptsText($post->content)) {
			return AtprotoPostRejectedException::TEXT_TOO_LONG;
		}

		return null;
	}
}
