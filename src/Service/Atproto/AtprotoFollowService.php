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
use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecord;

/** Durable, retry-safe outbound app.bsky.graph.follow operations. */
final class AtprotoFollowService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'atproto.publish';

	public function __construct(
		private readonly Config $config,
		private readonly AtprotoFollowRepository $follows,
		private readonly ?AtprotoAccountResolver $accounts,
		private readonly ?AtprotoPublisher $publisher,
		private readonly ?AtprotoDeleter $deleter,
		private readonly ?AtprotoRecordResolver $records,
		private readonly Clock $clock,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, false);
	}

	public function isAvailable(string $localUserId): bool
	{
		return $this->isEnabled() && $this->accounts?->resolve($localUserId) !== null
			&& $this->publisher !== null && $this->deleter !== null && $this->records !== null;
	}

	public function find(string $localUserId, string $subjectDid): ?AtprotoFollow
	{
		return $this->follows->findForSubject($localUserId, $subjectDid);
	}

	/** @return list<AtprotoFollow> */
	public function forUser(string $localUserId): array
	{
		return $this->follows->forUser($localUserId);
	}

	/**
	 * Repeated calls resume the same durable pending write and reuse its rkey.
	 * A response lost after the PDS accepted a write therefore cannot create a
	 * second follow record.
	 *
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoPublishFailedException
	 * @throws AtprotoFollowException
	 */
	public function follow(string $localUserId, string $subjectDid): AtprotoFollow
	{
		$this->assertEnabled();
		$this->assertConfigured();
		$this->assertSubject($subjectDid);
		$account = $this->account($localUserId);
		$existing = $this->follows->findForSubject($localUserId, $subjectDid);
		if ($existing !== null && $existing->accountDid !== $account->did) {
			throw new AtprotoFollowException(AtprotoFollowException::ACCOUNT_CHANGED, 'Reconnect the original AT Protocol account before changing this follow.');
		}
		if ($existing?->state === AtprotoFollow::ACTIVE) {
			return $existing;
		}

		$pending = $existing ?? $this->pending($localUserId, $account, $subjectDid);
		if ($existing === null) {
			try {
				$this->follows->save($pending);
			} catch (DatabaseException $exception) {
				// Another request may have inserted this relationship after our
				// initial read. Resume its durable intent and deterministic rkey.
				$pending = $this->follows->findForSubject($localUserId, $subjectDid)
					?? throw $exception;
				if ($pending->accountDid !== $account->did) {
					throw new AtprotoFollowException(AtprotoFollowException::ACCOUNT_CHANGED, 'Reconnect the original AT Protocol account before changing this follow.');
				}
			}
		} else {
			$this->follows->save($pending);
		}

		if ($pending->uri !== null) {
			$remote = $this->records->getRecord($account->pdsUrl, $pending->uri);
			if ($remote !== null) {
				return $this->activate($pending, $remote);
			}
		}

		$uri = $this->publisher->publish(
			$account,
			$pending->rkey,
			AtprotoFollowRecord::create($subjectDid, $pending->followedAt),
			AtprotoFollowRecord::COLLECTION,
		);
		$expectedUri = AtprotoIdentifiers::uri($account->did, AtprotoFollowRecord::COLLECTION, $pending->rkey);
		if ($uri !== $expectedUri) {
			throw new AtprotoFollowException(AtprotoFollowException::RECORD_MISMATCH, 'The PDS returned a different follow record.');
		}

		$pending = new AtprotoFollow(
			$pending->localUserId,
			$pending->accountDid,
			$pending->subjectDid,
			$pending->rkey,
			$uri,
			null,
			AtprotoFollow::PENDING,
			$pending->followedAt,
		);
		$this->follows->save($pending);
		$remote = $this->records->getRecord($account->pdsUrl, $uri);
		if ($remote === null) {
			throw new AtprotoFollowException(AtprotoFollowException::RECORD_UNAVAILABLE, 'The PDS did not return the new follow record. Retry the follow.');
		}

		return $this->activate($pending, $remote);
	}

	/**
	 * The record key is loaded from the local projection and deleted only from
	 * the PDS identity that created it. A disconnect or DID change cannot erase
	 * an unrelated record in a newly linked repository.
	 *
	 * @throws AtprotoPublishDisabledException
	 * @throws AtprotoNotConfiguredException
	 * @throws AtprotoAccountNotConnectedException
	 * @throws AtprotoPublishFailedException
	 * @throws AtprotoFollowException
	 */
	public function unfollow(string $localUserId, string $subjectDid): void
	{
		$this->assertEnabled();
		$this->assertConfigured();
		$this->assertSubject($subjectDid);
		$existing = $this->follows->findForSubject($localUserId, $subjectDid);
		if ($existing === null) {
			return;
		}

		$account = $this->account($localUserId);
		if ($existing->accountDid !== $account->did) {
			throw new AtprotoFollowException(AtprotoFollowException::ACCOUNT_CHANGED, 'Reconnect the original AT Protocol account before removing this follow.');
		}

		$this->deleter->delete($account, $existing->rkey, AtprotoFollowRecord::COLLECTION);
		$this->follows->remove($localUserId, $subjectDid);
	}

	private function activate(AtprotoFollow $pending, AtprotoRecord $remote): AtprotoFollow
	{
		$expectedUri = AtprotoIdentifiers::uri($pending->accountDid, AtprotoFollowRecord::COLLECTION, $pending->rkey);
		if ($remote->uri !== $expectedUri
			|| ($remote->record['$type'] ?? null) !== AtprotoFollowRecord::COLLECTION
			|| ($remote->record['subject'] ?? null) !== $pending->subjectDid
		) {
			throw new AtprotoFollowException(AtprotoFollowException::RECORD_MISMATCH, 'The PDS follow record does not match the requested account.');
		}

		$active = new AtprotoFollow(
			$pending->localUserId,
			$pending->accountDid,
			$pending->subjectDid,
			$pending->rkey,
			$expectedUri,
			$remote->cid,
			AtprotoFollow::ACTIVE,
			$pending->followedAt,
		);
		$this->follows->save($active);
		return $active;
	}

	private function pending(string $localUserId, AtprotoAccount $account, string $subjectDid): AtprotoFollow
	{
		$now = $this->clock->now();
		return new AtprotoFollow(
			$localUserId,
			$account->did,
			$subjectDid,
			AtprotoFollowRecord::rkey($now, $localUserId, $account->did, $subjectDid),
			null,
			null,
			AtprotoFollow::PENDING,
			$now,
		);
	}

	private function assertSubject(string $subjectDid): void
	{
		if (!AtprotoIdentifiers::isDid($subjectDid)) {
			throw new AtprotoFollowException(AtprotoFollowException::SUBJECT_INVALID, 'The target is not a valid AT Protocol DID.');
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

	private function assertEnabled(): void
	{
		if (!$this->isEnabled()) {
			throw new AtprotoPublishDisabledException();
		}
	}

	private function assertConfigured(): void
	{
		if ($this->publisher === null || $this->deleter === null || $this->records === null) {
			throw new AtprotoNotConfiguredException();
		}
	}
}
