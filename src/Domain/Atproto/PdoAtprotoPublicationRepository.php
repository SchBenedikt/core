<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use Throwable;

/**
 * AT Protocol publication ids in SQL.
 */
final class PdoAtprotoPublicationRepository implements AtprotoPublicationRepository
{
	private const COLUMNS = 'local_user_id, account_did, post_id, rkey, uri, published_at, pds_url, cid';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function findForPost(string $localUserId, string $postId, ?string $accountDid = null): ?AtprotoPublication
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoPublicationSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND post_id = :post_id'
			. ($accountDid === null ? ' ORDER BY account_did ASC LIMIT 1' : ' AND account_did = :account_did'),
			$accountDid === null ? ['local_user_id' => $localUserId, 'post_id' => $postId]
				: ['local_user_id' => $localUserId, 'post_id' => $postId, 'account_did' => $accountDid],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findAllForPost(string $localUserId, string $postId): array
	{
		return array_map(
			$this->hydrate(...),
			$this->db->fetchAll(
				'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoPublicationSchema::TABLE
				. ' WHERE local_user_id = :local_user_id AND post_id = :post_id ORDER BY account_did ASC',
				['local_user_id' => $localUserId, 'post_id' => $postId],
			),
		);
	}

	public function insert(AtprotoPublication $publication): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoPublicationSchema::TABLE
				. ' (local_user_id, account_did, post_id, rkey, uri, published_at, pds_url, cid)'
				. ' VALUES (:local_user_id, :account_did, :post_id, :rkey, :uri, :published_at, :pds_url, :cid)',
				[
					'local_user_id' => $publication->localUserId,
					'account_did' => $publication->accountDid,
					'post_id' => $publication->postId,
					'rkey' => $publication->rkey,
					'uri' => $publication->uri,
					'published_at' => Time::format($publication->publishedAt),
					'pds_url' => $publication->pdsUrl,
					'cid' => $publication->cid,
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The AT Protocol publication could not be stored.', 0, $exception);
		}
	}

	public function update(AtprotoPublication $publication): void
	{
		$this->db->execute(
			'UPDATE ' . AtprotoPublicationSchema::TABLE
			. ' SET uri = :uri, cid = :cid, published_at = :published_at, pds_url = :pds_url'
			. ' WHERE local_user_id = :local_user_id AND account_did = :account_did AND post_id = :post_id AND rkey = :rkey',
			[
				'uri' => $publication->uri,
				'cid' => $publication->cid,
				'published_at' => Time::format($publication->publishedAt),
				'pds_url' => $publication->pdsUrl,
				'local_user_id' => $publication->localUserId,
				'account_did' => $publication->accountDid,
				'post_id' => $publication->postId,
				'rkey' => $publication->rkey,
			],
		);
	}

	public function removeForPost(string $localUserId, string $postId, ?string $accountDid = null): bool
	{
		if ($accountDid === null) {
			$accountDid = $this->findForPost($localUserId, $postId)?->accountDid;
			if ($accountDid === null) { return false; }
		}
		return $this->db->execute(
			'DELETE FROM ' . AtprotoPublicationSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND account_did = :account_did AND post_id = :post_id',
			['local_user_id' => $localUserId, 'account_did' => $accountDid, 'post_id' => $postId],
		) > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): AtprotoPublication
	{
		return new AtprotoPublication(
			localUserId: (string) $row['local_user_id'],
			accountDid: (string) $row['account_did'],
			postId: (string) $row['post_id'],
			rkey: (string) $row['rkey'],
			uri: (string) $row['uri'],
			publishedAt: Time::parse((string) $row['published_at']),
			pdsUrl: $row['pds_url'] === null ? null : (string) $row['pds_url'],
			cid: ($row['cid'] ?? null) === null ? null : (string) $row['cid'],
		);
	}
}
