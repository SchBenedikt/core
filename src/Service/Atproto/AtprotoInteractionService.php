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
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Protocol\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoStrongRef;
use AlohaSocial\Core\Protocol\Atproto\Tid;

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
	public function like(string $localUserId, string $postId): string
	{
		return $this->write(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $postId);
	}

	/**
	 * @throws PostNotFoundException
	 */
	public function unlike(string $localUserId, string $postId): void
	{
		$this->remove(self::KIND_LIKE, AtprotoInteractionRecord::LIKE, $localUserId, $postId);
	}

	/**
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoInteractionRejectedException
	 * @throws PostNotFoundException
	 * @throws \AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException
	 */
	public function repost(string $localUserId, string $postId): string
	{
		return $this->write(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $postId);
	}

	/**
	 * @throws PostNotFoundException
	 */
	public function undoRepost(string $localUserId, string $postId): void
	{
		$this->remove(self::KIND_REPOST, AtprotoInteractionRecord::REPOST, $localUserId, $postId);
	}

	private function write(string $kind, string $collection, string $localUserId, string $postId): string
	{
		$this->assertEnabled();

		if ($this->publisher === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId);
		$post = $this->post($postId, true);
		$subject = $this->subject($post, $account);

		return $this->publisher->publish(
			$account,
			$this->rkey($kind, $localUserId, $post, $account),
			$this->record($kind, $subject),
			$collection,
		);
	}

	private function remove(string $kind, string $collection, string $localUserId, string $postId): void
	{
		$this->assertEnabled();

		if ($this->deleter === null) {
			throw new AtprotoNotConfiguredException();
		}

		$account = $this->account($localUserId);
		// A soft-deleted post still has a record key, so an undo after the
		// post was removed locally can clean up the remote interaction.
		$post = $this->post($postId, false);

		$this->deleter->delete(
			$account,
			$this->rkey($kind, $localUserId, $post, $account),
			$collection,
		);
	}

	private function assertEnabled(): void
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}
	}

	private function account(string $localUserId): AtprotoAccount
	{
		$account = $this->accounts?->resolve($localUserId);
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
		$publication = $this->publications->findForPost($post->authorId, $post->id);
		if ($publication === null) {
			throw new AtprotoInteractionRejectedException(
				AtprotoInteractionRejectedException::SUBJECT_NOT_PUBLISHED,
			);
		}

		if ($this->records === null) {
			throw new AtprotoNotConfiguredException();
		}

		$record = $this->records->getRecord($account->pdsUrl, $publication->uri);
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
