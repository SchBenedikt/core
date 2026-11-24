<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountCollectionResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublication;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordPublisher;
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
use AlohaSocial\Core\Protocol\Atproto\AtprotoExternalLink;
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

		$account = $this->accounts?->resolve($post->authorId);
		if ($account === null) {
			return;
		}

		$this->queue->push(JobType::ATPROTO_PUBLISH, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
			'account_did' => $account->did,
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

		$publications = $this->publications->findAllForPost($post->authorId, $post->id);
		if ($publications === []) {
			return;
		}

		foreach ($publications as $publication) {
			$this->queueDelete($post, $publication->accountDid);
		}
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

		$publications = $this->publications->findAllForPost($post->authorId, $post->id);
		if ($publications === []) {
			return;
		}

		if ($this->accounts?->resolve($post->authorId) === null) {
			return;
		}

		foreach ($publications as $publication) {
			if ($this->rejectionReason($post, accountDid: $publication->accountDid) !== null) {
				$this->queueDelete($post, $publication->accountDid);
				continue;
			}
			$this->queue->push(JobType::ATPROTO_PUBLISH, [
				'user_id' => $post->authorId,
				'post_id' => $post->id,
				'account_did' => $publication->accountDid,
				'update' => true,
			], subject: JobSubject::account($post->authorId));
		}
	}

	private function queueDelete(Post $post, string $accountDid): void
	{
		$this->queue->push(JobType::ATPROTO_DELETE, [
			'user_id' => $post->authorId,
			'post_id' => $post->id,
			'account_did' => $accountDid,
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
	public function deleteRemote(string $localUserId, string $postId, ?string $accountDid = null): void
	{
		if (!$this->isDeleteConfigured()) {
			throw new AtprotoNotConfiguredException();
		}

		$post = $this->posts->find($postId);
		if ($post !== null && $post->authorId !== $localUserId) {
			throw new PostNotFoundException($postId);
		}

		$account = $this->account($localUserId, $accountDid);
		$publication = $account === null ? $this->publications->findForPost($localUserId, $postId)
			: $this->publications->findForPost($localUserId, $postId, $account->did);
		if ($publication === null) {
			return;
		}

		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		$deleter = $this->deleter;
		if ($deleter === null) {
			throw new AtprotoNotConfiguredException();
		}

		$deleter->delete($account, $publication->rkey);
		$this->publications->removeForPost($localUserId, $postId, $publication->accountDid);
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
	public function publicationFor(string $localUserId, string $postId, ?string $accountDid = null): ?AtprotoPublication
	{
		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted() || $post->authorId !== $localUserId) {
			throw new PostNotFoundException($postId);
		}

		return $this->publications->findForPost($localUserId, $postId, $accountDid);
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
	public function publish(string $localUserId, string $postId, array $media = [], bool $update = false, ?string $accountDid = null): AtprotoPublication
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

		$account = $this->account($localUserId, $accountDid);
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		$existing = $this->publications->findForPost($localUserId, $postId, $account->did);
		if ($existing !== null && !$update) {
			return $existing;
		}
		if ($update && $existing === null) {
			throw new AtprotoPostRejectedException(AtprotoPostRejectedException::PUBLICATION_MISSING);
		}

		$rejection = $this->rejectionReason($post, $media, $account->did);
		if ($rejection !== null) {
			throw new AtprotoPostRejectedException($rejection);
		}

		if ($media === [] && $post->attachmentIds !== []) {
			$media = $this->storedMedia($post);
		}

		$rkey = Tid::forRecord($post->publishedAt, $post->id . '|' . $account->did);
		if ($existing !== null) {
			$expectedUri = 'at://' . $account->did . '/app.bsky.feed.post/' . $existing->rkey;
			if ($existing->uri !== $expectedUri) {
				throw new AtprotoAccountNotConnectedException();
			}

			$rkey = $existing->rkey;
		}
		$publisher = $this->publisher;
		$record = $this->record($post, $account, $media);
		$publishedRecord = $publisher instanceof AtprotoRecordPublisher
			? $publisher->publishRecord($account, $rkey, $record, swapRecord: $existing?->cid)
			: null;
		$uri = $publishedRecord?->uri ?? $publisher?->publish($account, $rkey, $record);
		$cid = $publishedRecord?->cid ?? $existing?->cid;

		if ($uri === null) {
			throw new AtprotoNotConfiguredException();
		}
		if ($existing !== null) {
			if ($uri !== $existing->uri) {
				throw new AtprotoPublishFailedException(502, 'The PDS changed the URI of an existing publication.');
			}

			$updated = new AtprotoPublication(
				localUserId: $existing->localUserId,
				postId: $existing->postId,
				rkey: $existing->rkey,
				uri: $existing->uri,
				publishedAt: Time::toSecondPrecision($this->clock->now()),
				pdsUrl: $account->pdsUrl,
				cid: $cid,
				accountDid: $account->did,
			);
			$this->publications->update($updated);

			return $updated;
		}

		$publication = new AtprotoPublication(
			localUserId: $localUserId,
			postId: $post->id,
			rkey: $rkey,
			uri: $uri,
			publishedAt: Time::toSecondPrecision($this->clock->now()),
			pdsUrl: $account->pdsUrl,
			cid: $cid,
			accountDid: $account->did,
		);

		try {
			$this->publications->insert($publication);
		} catch (DatabaseException $exception) {
			// A concurrent retry may have stored the same idempotency key
			// after the successful write. Return that record instead of
			// turning a successful publication into an error.
			$stored = $this->publications->findForPost($localUserId, $postId, $account->did);
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

	private function account(string $localUserId, ?string $accountDid): ?AtprotoAccount
	{
		if ($accountDid === null) {
			return $this->accounts?->resolve($localUserId);
		}

		if ($this->accounts instanceof AtprotoAccountCollectionResolver) {
			foreach ($this->accounts->resolveAll($localUserId) as $account) {
				if ($account->did === $accountDid) { return $account; }
			}
			return null;
		}

		$account = $this->accounts?->resolve($localUserId);
		return $account?->did === $accountDid ? $account : null;
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
		$quote = $this->quoteEmbed($post, $account->did);

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
		// A single external URL gets a metadata-free link card when there is no
		// richer media embed. Multiple links and media-plus-link posts keep their
		// links in the record text and facets instead of picking an arbitrary URL.
		if ($embed === null) {
			$embed = AtprotoExternalLink::fromText($post->content);
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
	private function quoteEmbed(Post $post, ?string $accountDid = null): ?array
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

		$publication = $this->publications->findForPost(
			$quotedPost->authorId,
			$quotedPost->id,
			$quotedPost->authorId === $post->authorId ? $accountDid : null,
		);
		$account = $publication === null ? null : $this->account($quotedPost->authorId, $publication->accountDid);
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

		$parent = $this->publications->findForPost($post->authorId, $post->inReplyToId, $account->did);
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
	private function rejectionReason(Post $post, array $media = [], ?string $accountDid = null): ?string
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
			if ($this->publications->findForPost($post->authorId, $post->inReplyToId, $accountDid) === null) {
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

			$publication = $this->publications->findForPost(
				$quotedPost->authorId,
				$quotedPost->id,
				$quotedPost->authorId === $post->authorId ? $accountDid : null,
			);
			$quoteAccount = $publication === null ? null : $this->account($quotedPost->authorId, $publication->accountDid);
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
