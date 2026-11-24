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
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRecord as AtprotoInteractionState;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Protocol\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoStrongRef;
use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

/**
 * Publishes and removes likes and reposts as AT Protocol records.
 *
 * A local interaction can only mirror a post that this instance already
 * published: the AT subject needs the record URI and its CID, and a CID cannot
 * be derived locally. The record key is derived from the subject post and the
 * interacting account, so a repeat of the same like or repost updates the same
 * record instead of creating a second one, and an undo removes exactly that
 * record without a local interaction table.
 *
 * Writes honor the instance's per-account local blocks in either direction.
 * This is a host-called operation with no route, like session refresh: the
 * host owns authentication and decides when a local favourite or boost becomes
 * an AT Protocol record.
 */
final class AtprotoInteractionService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'atproto.publish';

	private const KIND_LIKE = 'like';

	private const KIND_REPOST = 'repost';

	public function __construct(
		private readonly Config $config,
		private readonly PostRepository $posts,
		private readonly AtprotoPublicationRepository $publications,
		private readonly ?AtprotoAccountResolver $accounts,
		private readonly ?AtprotoPublisher $publisher,
		private readonly ?AtprotoRecordResolver $records,
		private readonly Clock $clock,
		private readonly ?AtprotoDeleter $deleter = null,
		private readonly ?BlockRepository $blocks = null,
		private readonly ?ActivityFactory $activities = null,
		private readonly ?AtprotoInteractionRepository $interactionRecords = null,
		private readonly ?AtprotoInteractionTargetPolicy $remoteTargetPolicy = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, false);
	}

	/**
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoInteractionRejectedException
	 * @throws PostNotFoundException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function like(string $localUserId, string $postId, ?string $accountDid = null): string
	{
		return $this->write(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $postId, $accountDid);
	}

	/**
	 * @throws PostNotFoundException
	 */
	public function unlike(string $localUserId, string $postId, ?string $accountDid = null): void
	{
		$this->remove(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $postId, $accountDid);
	}

	/**
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoInteractionRejectedException
	 * @throws PostNotFoundException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function repost(string $localUserId, string $postId, ?string $accountDid = null): string
	{
		return $this->write(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $postId, $accountDid);
	}

	/**
	 * @throws PostNotFoundException
	 */
	public function undoRepost(string $localUserId, string $postId, ?string $accountDid = null): void
	{
		$this->remove(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $postId, $accountDid);
	}

	/**
	 * Like a validated public post received from an AT Protocol feed.
	 *
	 * The host must supply a projection from its trusted sync/AppView path and
	 * a target policy that rechecks account visibility and local blocks. This
	 * method has no side effects on the host's local reaction counters.
	 */
	public function likeRemote(string $localUserId, AtprotoPostProjection $target, ?string $accountDid = null): string
	{
		return $this->writeRemote(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $target, $accountDid);
	}

	public function repostRemote(string $localUserId, AtprotoPostProjection $target, ?string $accountDid = null): string
	{
		return $this->writeRemote(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $target, $accountDid);
	}

	/** Removing an interaction stays available after the target is blocked or deleted. */
	public function unlikeRemote(string $localUserId, AtprotoPostProjection $target, ?string $accountDid = null): void
	{
		$this->removeRemote(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $target, $accountDid);
	}

	/** Remove a stored like after its remote post has been deleted from the host's feed. */
	public function unlikeRemoteUri(string $localUserId, string $postUri, ?string $accountDid = null): void
	{
		$this->removeRemoteByUri(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $postUri, $accountDid);
	}

	public function undoRemoteRepost(string $localUserId, AtprotoPostProjection $target, ?string $accountDid = null): void
	{
		$this->removeRemote(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $target, $accountDid);
	}

	/** Remove a stored repost after its remote post has been deleted from the host's feed. */
	public function undoRemoteRepostUri(string $localUserId, string $postUri, ?string $accountDid = null): void
	{
		$this->removeRemoteByUri(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $postUri, $accountDid);
	}

	private function writeRemote(
		string $kind,
		string $collection,
		string $localUserId,
		AtprotoPostProjection $target,
		?string $accountDid,
	): string {
		$this->assertEnabled();
		$this->assertRemoteTarget($localUserId, $target);
		if ($this->publisher === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId, $accountDid);
		$postId = self::remotePostKey($target);
		$existing = $this->interactionRecords?->find($localUserId, $account->did, $postId, $kind);
		if ($existing !== null) {
			return $existing->uri;
		}

		$subject = new AtprotoStrongRef($target->uri, $target->cid);
		$rkey = $this->remoteRkey($kind, $localUserId, $target, $account);
		$uri = $this->publisher->publish($account, $rkey, $this->record($kind, $subject), $collection);
		$this->storeRemoteInteraction($localUserId, $account, $postId, $kind, $uri, $rkey);

		return $uri;
	}

	private function removeRemote(
		string $kind,
		string $collection,
		string $localUserId,
		AtprotoPostProjection $target,
		?string $accountDid,
	): void {
		if ($this->deleter === null) {
			throw new AtprotoNotConfiguredException();
		}
		$this->assertRemoteRecord($target);

		$account = $this->account($localUserId, $accountDid);
		$postId = self::remotePostKey($target);
		$existing = $this->interactionRecords?->find($localUserId, $account->did, $postId, $kind);
		$rkey = $existing?->rkey ?? $this->remoteRkey($kind, $localUserId, $target, $account);
		$this->deleter->delete($account, $rkey, $collection);
		$this->interactionRecords?->remove($localUserId, $account->did, $postId, $kind);
	}

	private function removeRemoteByUri(
		string $kind,
		string $collection,
		string $localUserId,
		string $postUri,
		?string $accountDid,
	): void {
		$parts = explode('/', $postUri);
		if (!AtprotoIdentifiers::isAtUri($postUri)
			|| ($parts[3] ?? null) !== AtprotoPostProjection::COLLECTION
			|| AtprotoIdentifiers::uri((string) ($parts[2] ?? ''), AtprotoPostProjection::COLLECTION, (string) ($parts[4] ?? '')) !== $postUri
		) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE);
		}
		if ($this->deleter === null || $this->interactionRecords === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId, $accountDid);
		$postId = hash('sha256', $postUri);
		$existing = $this->interactionRecords->find($localUserId, $account->did, $postId, $kind);
		if ($existing === null) {
			return;
		}

		$this->deleter->delete($account, $existing->rkey, $collection);
		$this->interactionRecords->remove($localUserId, $account->did, $postId, $kind);
	}

	private function assertRemoteTarget(string $localUserId, AtprotoPostProjection $target): void
	{
		$this->assertRemoteRecord($target);
		if ($this->remoteTargetPolicy === null) {
			throw new AtprotoNotConfiguredException();
		}
		if (!$this->remoteTargetPolicy->allows($localUserId, $target)) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::BLOCKED_ACTOR);
		}
	}

	private function assertRemoteRecord(AtprotoPostProjection $target): void
	{
		$parts = explode('/', $target->uri);
		if (!AtprotoIdentifiers::isDid($target->did)
			|| !AtprotoIdentifiers::isAtUri($target->uri)
			|| ($parts[2] ?? null) !== $target->did
			|| ($parts[3] ?? null) !== AtprotoPostProjection::COLLECTION
			|| AtprotoIdentifiers::uri($target->did, AtprotoPostProjection::COLLECTION, (string) ($parts[4] ?? '')) !== $target->uri
			|| $target->cid === ''
		) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE);
		}
	}

	private static function remotePostKey(AtprotoPostProjection $target): string
	{
		return hash('sha256', $target->uri);
	}

	private function remoteRkey(
		string $kind,
		string $localUserId,
		AtprotoPostProjection $target,
		AtprotoAccount $account,
	): string {
		return Tid::forRecord(
			$target->createdAt,
			$kind . '|' . $localUserId . '|' . $target->uri . '|' . $account->did,
		);
	}

	private function storeRemoteInteraction(
		string $localUserId,
		AtprotoAccount $account,
		string $postId,
		string $kind,
		string $uri,
		string $rkey,
	): void {
		if ($this->interactionRecords === null) {
			return;
		}

		try {
			$this->interactionRecords->insert(new AtprotoInteractionState(
				localUserId: $localUserId,
				accountDid: $account->did,
				postId: $postId,
				kind: $kind,
				rkey: $rkey,
				uri: $uri,
				createdAt: $this->clock->now(),
			));
		} catch (DatabaseException $exception) {
			$stored = $this->interactionRecords->find($localUserId, $account->did, $postId, $kind);
			if ($stored === null) {
				throw $exception;
			}
		}
	}

	private function write(string $kind, string $collection, string $localUserId, string $postId, ?string $accountDid): string
	{
		$this->assertEnabled();

		if ($this->publisher === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId, $accountDid);
		$post = $this->post($postId, true);
		$this->assertNotBlocked($localUserId, $post->authorId);
		$existing = $this->interactionRecords?->find($localUserId, $account->did, $post->id, $kind);
		if ($existing !== null) {
			return $existing->uri;
		}
		$subject = $this->subject($post, $account);
		$rkey = $this->rkey($kind, $localUserId, $post, $account);
		$uri = $this->publisher->publish(
			$account,
			$rkey,
			$this->record($kind, $subject),
			$collection,
		);
		$publishedRkey = substr($uri, (int) strrpos($uri, '/') + 1);
		if ($publishedRkey === '' || strlen($publishedRkey) > 512) {
			throw new \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException(502, 'The AT Protocol PDS returned an invalid interaction URI.');
		}
		if ($this->interactionRecords !== null) {
			try {
				$this->interactionRecords->insert(new AtprotoInteractionState(
					localUserId: $localUserId,
					accountDid: $account->did,
					postId: $post->id,
					kind: $kind,
					rkey: $publishedRkey,
					uri: $uri,
					createdAt: $this->clock->now(),
				));
			} catch (DatabaseException $exception) {
				// Another request may have persisted the same PDS write first.
				$stored = $this->interactionRecords->find($localUserId, $account->did, $post->id, $kind);
				if ($stored === null) {
					throw $exception;
				}
				return $stored->uri;
			}
		}

		return $uri;
	}

	private function remove(string $kind, string $collection, string $localUserId, string $postId, ?string $accountDid): void
	{
		if ($this->deleter === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId, $accountDid);
		$stored = $this->interactionRecords?->find($localUserId, $account->did, $postId, $kind);
		if ($stored !== null) {
			$rkey = $stored->rkey;
		} else {
			// Legacy rows have no ledger entry; a soft-deleted post still has
			// its deterministic record key for cleanup.
			$post = $this->post($postId, false);
			$rkey = $this->rkey($kind, $localUserId, $post, $account);
		}

		$this->deleter->delete(
			$account,
			$rkey,
			$collection,
		);
		$this->interactionRecords?->remove($localUserId, $account->did, $postId, $kind);
	}

	/** Remove all recorded public interactions of one kind for opt-out cleanup. */
	public function removeAllForUser(string $localUserId, ?string $kind = null, ?string $accountDid = null): int
	{
		if ($this->interactionRecords === null) {
			return 0;
		}
		if ($this->deleter === null) {
			throw new AtprotoNotConfiguredException();
		}
		$records = array_values(array_filter(
			$this->interactionRecords->forUser($localUserId),
			static fn (AtprotoInteractionState $record): bool => ($kind === null || $record->kind === $kind)
				&& ($accountDid === null || $record->accountDid === $accountDid),
		));
		if ($records === []) {
			return 0;
		}

		$accounts = [];
		if ($accountDid !== null) {
			$account = $this->account($localUserId, $accountDid);
			$accounts[$account->did] = $account;
		} elseif ($this->accounts instanceof AtprotoAccountCollectionResolver) {
			foreach ($this->accounts->resolveAll($localUserId) as $account) {
				$accounts[$account->did] = $account;
			}
		} else {
			$account = $this->accounts?->resolve($localUserId);
			if ($account !== null) {
				$accounts[$account->did] = $account;
			}
		}

		// Do not report a complete opt-out while any recorded identity is
		// disconnected. The host can retain the ledger and show cleanup pending.
		foreach ($records as $record) {
			if (!isset($accounts[$record->accountDid])) {
				throw new AtprotoAccountNotConnectedException();
			}
		}

		$removed = 0;
		foreach ($records as $record) {
			$account = $accounts[$record->accountDid];
			$collection = $record->kind === self::KIND_LIKE
				? AtprotoInteractionRecord::LIKE
				: AtprotoInteractionRecord::REPOST;
			$this->deleter->delete($account, $record->rkey, $collection);
			if ($this->interactionRecords->remove($localUserId, $account->did, $record->postId, $record->kind)) {
				++$removed;
			}
		}

		return $removed;
	}

	private function assertEnabled(): void
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}
	}

	private function assertNotBlocked(string $localUserId, string $postAuthorId): void
	{
		if ($this->blocks === null || $this->activities === null) {
			throw new AtprotoNotConfiguredException();
		}

		try {
			$interactingActorId = $this->activities->actorId($localUserId);
			$postAuthorActorId = $this->activities->actorId($postAuthorId);
		} catch (ConfigurationException) {
			throw new AtprotoNotConfiguredException();
		}
		if ($this->blocks->findByActor($localUserId, $postAuthorActorId) !== null
			|| $this->blocks->findByActor($postAuthorId, $interactingActorId) !== null
		) {
			throw new AtprotoInteractionRejectedException(AtprotoInteractionRejectedException::BLOCKED_ACTOR);
		}
	}

	private function account(string $localUserId, ?string $accountDid = null): AtprotoAccount
	{
		$accounts = $this->accounts;
		if ($accountDid !== null) {
			if (!$accounts instanceof AtprotoAccountCollectionResolver) {
				throw new AtprotoAccountNotConnectedException();
			}
			$account = null;
			foreach ($accounts->resolveAll($localUserId) as $candidate) {
				if ($candidate->did === $accountDid) {
					$account = $candidate;
					break;
				}
			}
		} else {
			$account = $accounts?->resolve($localUserId);
		}
		if ($account === null) {
			throw new AtprotoAccountNotConnectedException();
		}

		return $account;
	}

	/**
	 * @throws PostNotFoundException
	 */
	private function post(string $postId, bool $mustBeLive): Post
	{
		$post = $this->posts->find($postId);
		if ($post === null || ($mustBeLive && $post->isDeleted())) {
			throw new PostNotFoundException($postId);
		}

		return $post;
	}

	/**
	 * @throws AtprotoInteractionRejectedException
	 */
	private function subject(Post $post, AtprotoAccount $account): AtprotoStrongRef
	{
		if (!$post->visibility->mayListPublicly()) {
			throw new AtprotoInteractionRejectedException(
				AtprotoInteractionRejectedException::SUBJECT_NOT_PUBLIC,
			);
		}

		$publication = $this->publications->findForPost($post->authorId, $post->id);
		if ($publication === null) {
			throw new AtprotoInteractionRejectedException(
				AtprotoInteractionRejectedException::SUBJECT_NOT_PUBLISHED,
			);
		}

		if ($this->records === null) {
			throw new AtprotoNotConfiguredException();
		}

		$pdsUrl = $publication->pdsUrl ?? $this->accounts?->resolve($post->authorId)?->pdsUrl;
		if ($pdsUrl === null) {
			throw new AtprotoInteractionRejectedException(
				AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE,
			);
		}
		$record = $this->records->getRecord($pdsUrl, $publication->uri);
		if ($record === null) {
			throw new AtprotoInteractionRejectedException(
				AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE,
			);
		}

		return new AtprotoStrongRef($record->uri, $record->cid);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(string $kind, AtprotoStrongRef $subject): array
	{
		$now = $this->clock->now();

		return $kind === self::KIND_LIKE
			? AtprotoInteractionRecord::like($subject, $now)
			: AtprotoInteractionRecord::repost($subject, $now);
	}

	/**
	 * The subject post and the interacting account decide the key, so a write
	 * and its undo meet on the same record without a local interaction row.
	 */
	private function rkey(string $kind, string $localUserId, Post $post, AtprotoAccount $account): string
	{
		return Tid::forRecord(
			$post->publishedAt,
			$kind . '|' . $localUserId . '|' . $post->id . '|' . $account->did,
		);
	}
}
