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

final class PdoAtprotoInteractionRepository implements AtprotoInteractionRepository
{
	private const COLUMNS = 'local_user_id, account_did, post_id, kind, rkey, uri, created_at';

	public function __construct(private readonly Connection $db)
	{
	}

	public function find(string $localUserId, string $accountDid, string $postId, string $kind): ?AtprotoInteractionRecord
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoInteractionSchema::TABLE
			. ' WHERE local_user_id = :user AND account_did = :did AND post_id = :post AND kind = :kind',
			['user' => $localUserId, 'did' => $accountDid, 'post' => $postId, 'kind' => $kind],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function insert(AtprotoInteractionRecord $record): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoInteractionSchema::TABLE
				. ' (local_user_id, account_did, post_id, kind, rkey, uri, created_at)'
				. ' VALUES (:user, :did, :post, :kind, :rkey, :uri, :created_at)',
				[
					'user' => $record->localUserId,
					'did' => $record->accountDid,
					'post' => $record->postId,
					'kind' => $record->kind,
					'rkey' => $record->rkey,
					'uri' => $record->uri,
					'created_at' => Time::format($record->createdAt),
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}
			throw new DatabaseException('The AT Protocol interaction could not be stored.', 0, $exception);
		}
	}

	public function remove(string $localUserId, string $accountDid, string $postId, string $kind): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . AtprotoInteractionSchema::TABLE
			. ' WHERE local_user_id = :user AND account_did = :did AND post_id = :post AND kind = :kind',
			['user' => $localUserId, 'did' => $accountDid, 'post' => $postId, 'kind' => $kind],
		) > 0;
	}

	public function forUser(string $localUserId): array
	{
		return array_map(
			fn (array $row): AtprotoInteractionRecord => $this->hydrate($row),
			$this->db->fetchAll(
				'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoInteractionSchema::TABLE
				. ' WHERE local_user_id = :user ORDER BY created_at, post_id, kind',
				['user' => $localUserId],
			),
		);
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): AtprotoInteractionRecord
	{
		return new AtprotoInteractionRecord(
			localUserId: (string) $row['local_user_id'],
			accountDid: (string) $row['account_did'],
			postId: (string) $row['post_id'],
			kind: (string) $row['kind'],
			rkey: (string) $row['rkey'],
			uri: (string) $row['uri'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
