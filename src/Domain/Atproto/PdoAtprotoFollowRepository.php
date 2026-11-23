<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

final class PdoAtprotoFollowRepository implements AtprotoFollowRepository
{
	private const COLUMNS = 'local_user_id, account_did, subject_did, rkey, uri, cid, state, followed_at';

	public function __construct(private readonly Connection $db)
	{
	}

	public function findForSubject(string $localUserId, string $subjectDid): ?AtprotoFollow
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoFollowSchema::TABLE
			. ' WHERE local_user_id = :user AND subject_did = :subject',
			['user' => $localUserId, 'subject' => $subjectDid],
		);
		return $row === null ? null : $this->hydrate($row);
	}

	public function forUser(string $localUserId): array
	{
		return array_map(
			fn (array $row): AtprotoFollow => $this->hydrate($row),
			$this->db->fetchAll(
				'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoFollowSchema::TABLE
				. ' WHERE local_user_id = :user ORDER BY followed_at DESC, subject_did DESC',
				['user' => $localUserId],
			),
		);
	}

	public function save(AtprotoFollow $follow): void
	{
		$parameters = [
			'user' => $follow->localUserId,
			'account' => $follow->accountDid,
			'subject' => $follow->subjectDid,
			'rkey' => $follow->rkey,
			'uri' => $follow->uri,
			'cid' => $follow->cid,
			'state' => $follow->state,
			'followed_at' => Time::format($follow->followedAt),
		];
		$update = 'UPDATE ' . AtprotoFollowSchema::TABLE
			. ' SET account_did = :account, rkey = :rkey, uri = :uri, cid = :cid, state = :state, followed_at = :followed_at'
			. ' WHERE local_user_id = :user AND subject_did = :subject';
		if ($this->findForSubject($follow->localUserId, $follow->subjectDid) !== null) {
			$this->db->execute($update, $parameters);
			return;
		}

		$this->db->execute(
			'INSERT INTO ' . AtprotoFollowSchema::TABLE
			. ' (' . self::COLUMNS . ') VALUES (:user, :account, :subject, :rkey, :uri, :cid, :state, :followed_at)',
			$parameters,
		);
	}

	public function remove(string $localUserId, string $subjectDid): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . AtprotoFollowSchema::TABLE . ' WHERE local_user_id = :user AND subject_did = :subject',
			['user' => $localUserId, 'subject' => $subjectDid],
		) > 0;
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): AtprotoFollow
	{
		return new AtprotoFollow(
			(string) $row['local_user_id'],
			(string) $row['account_did'],
			(string) $row['subject_did'],
			(string) $row['rkey'],
			is_string($row['uri']) ? $row['uri'] : null,
			is_string($row['cid']) ? $row['cid'] : null,
			(string) $row['state'],
			Time::parse((string) $row['followed_at']),
		);
	}
}
