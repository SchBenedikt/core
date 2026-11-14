<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\QuoteGrant;
use AlohaSocial\Core\Domain\Post\QuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteState;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;

use function hash;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function substr;

/**
 * FEP-044f quote authorization and the local quote policy.
 *
 * Two local posts are granted synchronously. A quote of a remote post starts
 * pending and a QuoteRequest is delivered to the quoted author; Accept/Reject
 * updates the stored post. An inbound QuoteRequest for one of our public or
 * unlisted posts is answered by policy, not by a human: the grant is stored,
 * so the authorization document can be resolved and later revoked.
 */
final class QuoteService
{
	public const MAX_URL_LENGTH = 1024;

	public function __construct(
		private readonly PostRepository $posts,
		private readonly RemotePostRepository $remotePosts,
		private readonly QuoteGrantRepository $grants,
		private readonly JobQueue $queue,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
		private readonly ?ActorInboxResolver $inboxes = null,
		private readonly ?BlockService $blocks = null,
	) {
	}

	public static function grantId(string $quotedObjectId, string $interactingObjectId): string
	{
		return substr(hash('sha256', $quotedObjectId . "\0" . $interactingObjectId), 0, 32);
	}

	/**
	 * Validates the target and decides the initial state before the post row
	 * is written. No grant or delivery is created here; commit() does that
	 * inside the post's write transaction.
	 *
	 * @throws ValidationException when the target is missing, private or blocked
	 */
	public function prepare(string $authorId, string $quotePostId, string $targetId): QuoteDraft
	{
		$targetId = trim($targetId);
		$local = $this->posts->find($targetId);

		if ($local !== null) {
			if ($local->isDeleted() || !$local->visibility->isPubliclyVisible()) {
				throw new ValidationException([
					'quoted_post_id' => 'Only a live public or unlisted post can be quoted.',
				]);
			}

			$quotedObjectId = $this->activities->noteIdFor($local->id);
			$quotedActorId = $this->activities->actorId($local->authorId);
			$grantId = self::grantId($quotedObjectId, $this->activities->noteIdFor($quotePostId));

			return new QuoteDraft(
				quotePostId: $quotePostId,
				quotedObjectId: $quotedObjectId,
				state: QuoteState::Accepted,
				authorizationId: $this->activities->quoteAuthorizationId($grantId),
				localQuotedPostId: $local->id,
				quotedActorId: $quotedActorId,
			);
		}

		$remote = $this->remotePosts->findPostByObjectId($targetId)
			?? $this->remotePosts->findByActivityId($targetId);

		if ($remote === null || $remote->isBoost()) {
			throw new ValidationException([
				'quoted_post_id' => 'The quote target does not exist or is not visible to this account.',
			]);
		}

		if ($this->blocks?->isBlocked($authorId, $remote->actorId) === true) {
			throw new ValidationException([
				'quoted_post_id' => 'This actor is blocked by the signed-in account.',
			]);
		}

		return new QuoteDraft(
			quotePostId: $quotePostId,
			quotedObjectId: $remote->objectId,
			state: QuoteState::Pending,
			requestId: $this->activities->quoteRequestId($quotePostId),
			quotedActorId: $remote->actorId,
			remoteTargetActorId: $remote->actorId,
		);
	}

	/**
	 * Issues the local grant or delivers the pending QuoteRequest.
	 */
	public function commit(QuoteDraft $draft, string $authorId): void
	{
		$interacting = $this->activities->noteIdFor($draft->quotePostId);
		$now = Time::toSecondPrecision($this->clock->now());

		if ($draft->state->isAccepted() && $draft->localQuotedPostId !== null && $draft->quotedActorId !== null) {
			$this->grants->insert(new QuoteGrant(
				id: self::grantId($draft->quotedObjectId, $interacting),
				quotedObjectId: $draft->quotedObjectId,
				interactingObjectId: $interacting,
				actorId: $this->activities->actorId($authorId),
				quotedActorId: $draft->quotedActorId,
				state: QuoteState::Accepted,
				createdAt: $now,
				updatedAt: $now,
			));

			return;
		}

		if ($draft->remoteTargetActorId === null || $this->inboxes === null) {
			return;
		}

		$inbox = $this->inboxes->inboxFor($draft->remoteTargetActorId);
		if ($inbox === null || $inbox === '') {
			// The quote stays pending; a retry can still request a grant.
			return;
		}

		$this->queue->push(JobType::FEDERATION_DELIVER, [
			'inbox' => $inbox,
			'activity' => $this->activities->quoteRequest(
				$authorId,
				$draft->quotePostId,
				$draft->quotedObjectId,
				$draft->remoteTargetActorId,
			),
			'key_id' => $this->activities->keyId($authorId),
		]);
	}

	/**
	 * Answers a remote QuoteRequest for one of our posts.
	 *
	 * A request is accepted only for a live public or unlisted post whose
	 * author has not blocked the requester. A refusal is a signed Reject, so
	 * the requester never has to guess whether its request was processed.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws InboxRejectedException when the request does not name one of our posts
	 */
	public function handleQuoteRequest(string $remoteActorId, string $inboxUrl, array $activity): void
	{
		$object = $activity['object'] ?? null;
		$instrument = $activity['instrument'] ?? null;

		if (!is_string($object) || !is_string($instrument)) {
			throw new InboxRejectedException(
				'invalid_activity',
				'A QuoteRequest needs string "object" and "instrument" URLs.',
			);
		}

		$postId = $this->activities->postIdFromUrl(trim($object));
		$instrument = trim($instrument);

		if ($postId === null) {
			throw new InboxRejectedException(
				'quote_not_available',
				'The QuoteRequest does not name a post on this instance.',
			);
		}

		if (mb_strlen($instrument) > self::MAX_URL_LENGTH
			|| !in_array(parse_url($instrument, PHP_URL_SCHEME), ['http', 'https'], true)
		) {
			throw new InboxRejectedException('invalid_activity', 'The quoting object must be an http(s) URL.');
		}

		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted()) {
			throw new InboxRejectedException('quote_not_available', 'The quoted post is not available.');
		}

		$grantId = self::grantId(trim($object), trim($instrument));
		$existing = $this->grants->find($grantId);
		$allowed = $post->visibility->isPubliclyVisible()
			&& $this->blocks?->isBlocked($post->authorId, $remoteActorId) !== true
			&& ($existing === null || $existing->isActive());

		if ($allowed && $existing === null) {
			$this->grants->insert(new QuoteGrant(
				id: $grantId,
				quotedObjectId: trim($object),
				interactingObjectId: trim($instrument),
				actorId: $remoteActorId,
				quotedActorId: $this->activities->actorId($post->authorId),
				state: QuoteState::Accepted,
				createdAt: Time::toSecondPrecision($this->clock->now()),
				updatedAt: Time::toSecondPrecision($this->clock->now()),
			));
			$existing = $this->grants->find($grantId);
		}

		if ($allowed && $existing !== null) {
			$response = $this->activities->acceptQuoteRequest($post->authorId, $activity, $existing);
		} else {
			$response = $this->activities->rejectQuoteRequest($post->authorId, $activity, $remoteActorId);
		}

		$this->queue->push(JobType::FEDERATION_DELIVER, [
			'inbox' => $inboxUrl,
			'activity' => $response,
			'key_id' => $this->activities->keyId($post->authorId),
		]);
	}

	/**
	 * Applies an Accept or Reject for one of our pending quotes.
	 *
	 * Returns false when the activity is not a quote response, so the inbox
	 * can keep its Follow handling. Only the author of the quoted remote post
	 * may answer; a stale response for a final quote is an idempotent no-op.
	 *
	 * @param array<string, mixed> $activity
	 */
	public function handleResponse(string $actorId, array $activity, QuoteState $state): bool
	{
		$object = $activity['object'] ?? null;
		$request = is_array($object) && ($object['type'] ?? null) === 'QuoteRequest' ? $object : null;
		if ($request === null) {
			return false;
		}

		$instrument = $request['instrument'] ?? null;
		$quoted = $request['object'] ?? null;
		if (!is_string($instrument) || !is_string($quoted)) {
			throw new InboxRejectedException('invalid_activity', 'A quote response needs a complete QuoteRequest.');
		}

		$quotePostId = $this->activities->postIdFromUrl(trim($instrument));
		if ($quotePostId === null) {
			return false;
		}

		$post = $this->posts->find($quotePostId);
		if ($post === null || $post->quotedObjectId === null || $post->quoteState === null) {
			return false;
		}

		$target = $this->remotePosts->findPostByObjectId($post->quotedObjectId);
		if ($target === null || $target->actorId !== $actorId || $target->objectId !== trim($quoted)) {
			return false;
		}

		if ($post->quoteState->isFinal()) {
			return true;
		}

		$authorization = null;
		if ($state === QuoteState::Accepted) {
			$result = $activity['result'] ?? null;
			$authorization = is_array($result) ? ($result['id'] ?? null) : $result;
			if (!is_string($authorization) || trim($authorization) === '') {
				throw new InboxRejectedException(
					'invalid_activity',
					'An accepted quote needs an authorization document.',
				);
			}

			$authorization = trim($authorization);
			if (mb_strlen($authorization) > self::MAX_URL_LENGTH) {
				throw new InboxRejectedException('invalid_activity', 'The quote authorization URL is too long.');
			}

			$scheme = parse_url($authorization, PHP_URL_SCHEME);
			if ($scheme !== 'http' && $scheme !== 'https') {
				throw new InboxRejectedException('invalid_activity', 'The quote authorization must be an http(s) URL.');
			}
		}

		$this->posts->updateQuoteState($quotePostId, $state, $authorization);

		return true;
	}

	/**
	 * Revokes a grant. Only the quoted post's author may do so.
	 */
	public function revoke(string $grantId, string $localUserId): QuoteGrant
	{
		$grant = $this->grants->find($grantId);
		if ($grant === null) {
			throw new QuoteNotFoundException($grantId);
		}

		if ($grant->quotedActorId !== $this->activities->actorId($localUserId)) {
			throw new QuoteAccessDeniedException($grantId, $localUserId);
		}

		$now = Time::toSecondPrecision($this->clock->now());
		$this->grants->close($grantId, QuoteState::Revoked, $now);
		$grant = $this->grants->find($grantId) ?? $grant;

		$quotePostId = $this->activities->postIdFromUrl($grant->interactingObjectId);
		if ($quotePostId !== null) {
			$this->posts->updateQuoteState($quotePostId, QuoteState::Revoked);
		}

		return $grant;
	}

	/**
	 * The dereferenceable authorization, or a not-found when the grant or the
	 * quoted post is no longer public.
	 *
	 * @return array<string, mixed>
	 */
	public function authorizationDocument(string $grantId): array
	{
		$grant = $this->grants->find($grantId);
		if ($grant === null || !$grant->isActive()) {
			throw new QuoteNotFoundException($grantId);
		}

		$quotedPostId = $this->activities->postIdFromUrl($grant->quotedObjectId);
		$quoted = $quotedPostId === null ? null : $this->posts->find($quotedPostId);
		if ($quoted === null || $quoted->isDeleted() || !$quoted->visibility->isPubliclyVisible()) {
			throw new QuoteNotFoundException($grantId);
		}

		// A local quote has a local interacting post; a grant issued to a remote
		// requester names that peer's post instead, which cannot be resolved
		// here and is validated by the grant itself.
		$quotePostId = $this->activities->postIdFromUrl($grant->interactingObjectId);
		if ($quotePostId !== null) {
			$quote = $this->posts->find($quotePostId);
			if ($quote === null || $quote->isDeleted()) {
				throw new QuoteNotFoundException($grantId);
			}
		}

		return $this->activities->quoteAuthorization($grant);
	}

	/**
	 * Whether a stored quote may still be shown with its target.
	 *
	 * A revoked or rejected grant, a deleted target or a now-private target
	 * removes the preview instead of exposing what the author withdrew.
	 */
	public function isPreviewable(Post $quote): bool
	{
		if ($quote->quotedObjectId === null || $quote->quoteState?->isAccepted() !== true) {
			return false;
		}

		$quotedPostId = $this->activities->postIdFromUrl($quote->quotedObjectId);
		$quoted = $quotedPostId === null ? null : $this->posts->find($quotedPostId);

		return $quoted !== null && !$quoted->isDeleted() && $quoted->visibility->isPubliclyVisible();
	}
}
