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
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\QuoteState;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbed;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbedException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacetException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoQuoteBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoQuoteException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyRef;
use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\MediaService;
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
		private readonly ?AtprotoEmbed $embeds = null,
		private readonly ?MediaService $mediaService = null,
		private readonly ?AtprotoQuoteBuilder $quotes = null,
		private readonly ?ActivityFactory $activities = null,
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
		], subject: JobSubject::account($post->authorId));
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
		if (!$this->isDeleteConfigured()) {
			return;
		}

		if ($this->publications->findForPost($post->authorId, $post->id) === null) {
			return;
		}

		$this->queue->push(JobType::ATPROTO_DELETE, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
		], subject: JobSubject::account($post->authorId));
	}

	/**
	 * Queues an upsert of an existing public post record after a local edit.
	 *
	 * AT Protocol has no in-place edit operation, but the PDS record key is
	 * stable, so `putRecord` can replace the record while preserving its URI.
	 * If the edited post can no longer be represented, remove its public mirror
	 * instead of leaving stale content visible there.
	 */
	public function scheduleUpdate(Post $post): void
	{
		if (!$this->isEnabled() || !$this->isConfigured()) {
			return;
		}

		if ($this->publications->findForPost($post->authorId, $post->id) === null) {
			return;
		}

		if ($this->rejectionReason($post) !== null) {
			$this->scheduleDelete($post);
			return;
		}

		if ($this->accounts?->resolve($post->authorId) === null) {
			return;
		}

		$this->queue->push(JobType::ATPROTO_PUBLISH, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
			'update' => true,
		], subject: JobSubject::account($post->authorId));
	}

	/**
	 * Deletes the remote AT Protocol record for a local post and removes the
	 * local publication row. Repeating this operation is a no-op.
	 *
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws PostNotFoundException when a still-present post belongs to another user
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function deleteRemote(string $localUserId, string $postId): void
	{
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
	/**
	 * @param list<AtprotoMedia> $media images or one video to upload and embed
	 */
	public function publish(string $localUserId, string $postId, array $media = [], bool $update = false): AtprotoPublication
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

		$existing = $this->publications->findForPost($localUserId, $postId);
		if ($existing !== null && !$update) {
			return $existing;
		}
		if ($update && $existing === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::PUBLICATION_MISSING);
		}

		$rejection = $this->rejectionReason($post, $media);
		if ($rejection !== null) {
			throw new AtprotoPostRejectedException($rejection);
		}

		if ($media === [] && $post->attachmentIds !== []) {
			$media = $this->storedMedia($post);
		}

		$account = $this->accounts?->resolve($localUserId);
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		$rkey = Tid::forRecord($post->publishedAt, $post->id . '|' . $account->did);
		if ($existing !== null) {
			$expectedUri = 'at://' . $account->did . '/app.bsky.feed.post/' . $existing->rkey;
			if ($existing->uri !== $expectedUri) {
				throw new AtprotoAccountNotConnectedException();
			}

			$rkey = $existing->rkey;
		}
		$uri = $this->publisher?->publish(
			$account,
			$rkey,
			$this->record($post, $account, $media),
		);

		if ($uri === null) {
			throw new AtprotoNotConfiguredException();
		}
		if ($existing !== null) {
			if ($uri !== $existing->uri) {
				throw new AtprotoPublishFailedException(502, 'The PDS changed the URI of an existing publication.');
			}

			return $existing;
		}

		$publication = new AtprotoPublication(
			localUserId: $localUserId,
			postId: $post->id,
			rkey: $rkey,
			uri: $uri,
			publishedAt: Time::toSecondPrecision($this->clock->now()),
			pdsUrl: $account->pdsUrl,
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
	private function record(Post $post, AtprotoAccount $account, array $media = []): array
	{
		$reply = $this->replyReference($post, $account);
		$facets = [];
		$embed = null;
		$quote = $this->quoteEmbed($post);

		if ($this->facets !== null) {
			try {
				$facets = $this->facets->build($post->content, $account->pdsUrl);
			} catch (AtprotoFacetException $exception) {
				throw new AtprotoPostRejectedException($exception->reason);
			}
		}

		if ($media !== []) {
			if ($this->embeds === null) {
				throw new AtprotoPostRejectedException(AtprotoPostRejectedException::MEDIA_UNSUPPORTED);
			}

			try {
				$embed = $this->embeds->build($media, $account);
			} catch (AtprotoBlobException | AtprotoEmbedException $exception) {
				throw new AtprotoPostRejectedException($exception->reason);
			}
		}
		if ($quote !== null) {
			$embed = $embed === null
				? $quote
				: [
					'$type' => 'app.bsky.embed.recordWithMedia',
					'record' => $quote,
					'media' => $embed,
				];
		}

		return AtprotoPostRecord::fromPost($post, $reply, $facets, $embed);
	}

	/**
	 * Builds an authorized local quote reference from its published record.
	 * Quotes that are pending, rejected or revoked stay as text only; an
	 * accepted quote that cannot be represented is refused rather than silently
	 * published without its authorized target.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws AtprotoPostRejectedException
	 */
	private function quoteEmbed(Post $post): ?array
	{
		if ($post->quotedObjectId === null || $post->quoteState !== QuoteState::Accepted) {
			return null;
		}

		$quotePostId = $this->activities?->postIdFromUrl($post->quotedObjectId);
		if ($quotePostId === null || $this->quotes === null || $this->activities === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::QUOTE_UNSUPPORTED);
		}

		$quotedPost = $this->posts->find($quotePostId);
		if ($quotedPost === null || $quotedPost->isDeleted() || !$quotedPost->visibility->mayListPublicly()) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::QUOTE_UNSUPPORTED);
		}

		$publication = $this->publications->findForPost($quotedPost->authorId, $quotedPost->id);
		$account = $this->accounts?->resolve($quotedPost->authorId);
		if ($publication === null || $account === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::QUOTE_TARGET_NOT_PUBLISHED);
		}

		$expectedUri = 'at://' . $account->did . '/app.bsky.feed.post/' . $publication->rkey;
		if ($publication->uri !== $expectedUri) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::QUOTE_TARGET_NOT_PUBLISHED);
		}

		try {
			return $this->quotes->build($account->pdsUrl, $publication->uri);
		} catch (AtprotoQuoteException) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::QUOTE_TARGET_UNRESOLVABLE);
		}
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

	/**
	 * @param list<AtprotoMedia> $media
	 */
	private function rejectionReason(Post $post, array $media = []): ?string
	{
		if ($post->visibility !== Visibility::Public) {
			return AtprotoPostRejectedException::NOT_PUBLIC;
		}

		// Scheduling checks only attachment metadata. The worker reads the
		// bounded bytes immediately before upload.
		if ($post->attachmentIds !== [] && $media === [] && $this->mediaService === null) {
			return AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED;
		}
		if ($post->attachmentIds !== [] && $media === [] && $this->embeds === null) {
			return AtprotoPostRejectedException::MEDIA_UNSUPPORTED;
		}
		if ($post->attachmentIds !== [] && $media === []) {
			try {
				$this->storedAttachments($post);
			} catch (AtprotoPostRejectedException $exception) {
				return $exception->reason;
			}
		}

		if ($media !== [] && $this->embeds === null) {
			return AtprotoPostRejectedException::MEDIA_UNSUPPORTED;
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

		if ($post->quotedObjectId !== null && $post->quoteState === QuoteState::Accepted) {
			if ($this->quotes === null || $this->activities === null) {
				return AtprotoPostRejectedException::QUOTE_UNSUPPORTED;
			}

			$quotePostId = $this->activities->postIdFromUrl($post->quotedObjectId);
			$quotedPost = $quotePostId === null ? null : $this->posts->find($quotePostId);
			if ($quotedPost === null || $quotedPost->isDeleted() || !$quotedPost->visibility->mayListPublicly()) {
				return AtprotoPostRejectedException::QUOTE_UNSUPPORTED;
			}

			$publication = $this->publications->findForPost($quotedPost->authorId, $quotedPost->id);
			$quoteAccount = $this->accounts?->resolve($quotedPost->authorId);
			if ($publication === null || $quoteAccount === null) {
				return AtprotoPostRejectedException::QUOTE_TARGET_NOT_PUBLISHED;
			}

			if ($publication->uri !== 'at://' . $quoteAccount->did . '/app.bsky.feed.post/' . $publication->rkey) {
				return AtprotoPostRejectedException::QUOTE_TARGET_NOT_PUBLISHED;
			}
		}

		return null;
	}

	/**
	 * Reads only the bounded image/video set the Bluesky record can represent.
	 * Check all metadata before reading any bytes so oversized or mixed posts do
	 * not cause an avoidable storage read or partial PDS upload.
	 *
	 * @return list<AtprotoMedia>
	 *
	 * @throws AtprotoPostRejectedException
	 */
	private function storedMedia(Post $post): array
	{
		$mediaService = $this->mediaService;
		if ($mediaService === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED);
		}

		$attachments = $this->storedAttachments($post);

		return array_map(
			static fn (Attachment $attachment): AtprotoMedia => new AtprotoMedia(
				$attachment->mimeType,
				$mediaService->read($attachment),
				$attachment->altText,
				$attachment->width > 0 ? $attachment->width : null,
				$attachment->height > 0 ? $attachment->height : null,
			),
			$attachments,
		);
	}

	/**
	 * @return list<Attachment>
	 *
	 * @throws AtprotoPostRejectedException
	 */
	private function storedAttachments(Post $post): array
	{
		$mediaService = $this->mediaService;
		if ($mediaService === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED);
		}

		$attachments = [];
		$imageCount = 0;
		$videoCount = 0;
		foreach ($post->attachmentIds as $id) {
			$attachment = $mediaService->find($id);
			if ($attachment === null || $attachment->ownerId !== $post->authorId) {
				throw new AtprotoPostRejectedException(AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED);
			}
			if (!AtprotoEmbed::supportsMimeType($attachment->mimeType)) {
				throw new AtprotoPostRejectedException(AtprotoBlobException::MIME_UNSUPPORTED);
			}

			if ($attachment->kind === MediaKind::Image) {
				$imageCount++;
				if ($attachment->byteSize > AtprotoEmbed::MAX_IMAGE_BYTES) {
					throw new AtprotoPostRejectedException(AtprotoBlobException::TOO_LARGE);
				}
				if ($attachment->altText === null) {
					throw new AtprotoPostRejectedException(AtprotoEmbedException::ALT_MISSING);
				}
			} elseif ($attachment->kind === MediaKind::Video) {
				$videoCount++;
				if ($attachment->byteSize > AtprotoEmbed::MAX_VIDEO_BYTES) {
					throw new AtprotoPostRejectedException(AtprotoBlobException::TOO_LARGE);
				}
			} else {
				throw new AtprotoPostRejectedException(AtprotoPostRejectedException::MEDIA_UNSUPPORTED);
			}

			$attachments[] = $attachment;
		}

		if ($imageCount > AtprotoEmbed::MAX_IMAGES) {
			throw new AtprotoPostRejectedException(AtprotoEmbedException::TOO_MANY_IMAGES);
		}
		if ($videoCount > AtprotoEmbed::MAX_VIDEOS) {
			throw new AtprotoPostRejectedException(AtprotoEmbedException::TOO_MANY_VIDEOS);
		}
		if ($imageCount > 0 && $videoCount > 0) {
			throw new AtprotoPostRejectedException(AtprotoEmbedException::MIXED_MEDIA);
		}

		return $attachments;
	}
}
