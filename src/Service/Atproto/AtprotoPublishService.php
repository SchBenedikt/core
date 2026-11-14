<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
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
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacetException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyRef;
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
		private readonly ?AtprotoDeleter $deleter = null,
		private readonly ?AtprotoFacets $facets = null,
		private readonly ?AtprotoReplyBuilder $replies = null,
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
	 * Queues a best-effort remote delete after a local post was soft-deleted.
	 *
	 * Only a post with a stored publication needs a remote delete. A post whose
	 * publish job has not run yet has no remote record; its queued publish job
	 * will see the deleted post and skip.
	 */
	public function scheduleDelete(Post $post): void
	{
		if (!$this->isEnabled() || !$this->isDeleteConfigured()) {
			return;
		}

		if ($this->publications->findForPost($post->authorId, $post->id) === null) {
			return;
		}

		$this->queue->push(JobType::ATPROTO_DELETE, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
		]);
	}

	/**
	 * Deletes the remote AT Protocol record for a local post and removes the
	 * local publication row. Repeating this operation is a no-op.
	 *
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws PostNotFoundException when a still-present post belongs to another user
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function deleteRemote(string $localUserId, string $postId): void
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}

		if (!$this->isDeleteConfigured()) {
			throw new AtprotoNotConfiguredException();
		}

		$post = $this->posts->find($postId);
		if ($post !== null && $post->authorId !== $localUserId) {
			throw new PostNotFoundException($postId);
		}

		$publication = $this->publications->findForPost($localUserId, $postId);
		if ($publication === null) {
			return;
		}

		$account = $this->accounts?->resolve($localUserId);
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		$deleter = $this->deleter;
		if ($deleter === null) {
			throw new AtprotoNotConfiguredException();
		}

		$deleter->delete($account, $publication->rkey);
		$this->publications->removeForPost($localUserId, $postId);
	}

	/**
	 * Returns the stored publication of one of the caller's posts.
	 *
	 * This reads the local idempotency record, not the PDS: it stays
	 * available when publishing is disabled, and a post that was never
	 * published returns null instead of triggering a write.
	 *
	 * @throws PostNotFoundException when the post is missing, deleted or owned by another account
	 */
	public function publicationFor(string $localUserId, string $postId): ?AtprotoPublication
	{
		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted() || $post->authorId !== $localUserId) {
			throw new PostNotFoundException($postId);
		}

		return $this->publications->findForPost($localUserId, $postId);
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
			$this->record($post, $account),
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

	private function isDeleteConfigured(): bool
	{
		return $this->accounts !== null && $this->deleter !== null;
	}

	/**
	 * Builds the record for one post: its reply reference and the facets that
	 * the text produces. Both need the PDS, so a post that cannot be resolved
	 * is refused instead of published with a partial record.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoPostRejectedException
	 */
	private function record(Post $post, AtprotoAccount $account): array
	{
		$reply = $this->replyReference($post, $account);
		$facets = [];

		if ($this->facets !== null) {
			try {
				$facets = $this->facets->build($post->content, $account->pdsUrl);
			} catch (AtprotoFacetException $exception) {
				throw new AtprotoPostRejectedException($exception->reason);
			}
		}

		return AtprotoPostRecord::fromPost($post, $reply, $facets);
	}

	/**
	 * @throws AtprotoPostRejectedException
	 */
	private function replyReference(Post $post, AtprotoAccount $account): ?AtprotoReplyRef
	{
		if ($post->inReplyToId === null) {
			return null;
		}

		$parent = $this->publications->findForPost($post->authorId, $post->inReplyToId);
		if ($parent === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::REPLY_PARENT_NOT_PUBLISHED);
		}

		if ($this->replies === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::REPLIES_UNSUPPORTED);
		}

		try {
			return $this->replies->build($account->pdsUrl, $parent->uri);
		} catch (AtprotoReplyException $exception) {
			throw new AtprotoPostRejectedException($exception->reason);
		}
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

		if ($post->inReplyToId !== null) {
			if ($this->replies === null) {
				return AtprotoPostRejectedException::REPLIES_UNSUPPORTED;
			}

			// Only a parent that was itself published to AT Protocol has a
			// record to reply to; a local-only parent has no AT URI.
			if ($this->publications->findForPost($post->authorId, $post->inReplyToId) === null) {
				return AtprotoPostRejectedException::REPLY_PARENT_NOT_PUBLISHED;
			}
		}

		return null;
	}
}
