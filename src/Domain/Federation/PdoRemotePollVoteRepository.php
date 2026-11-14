<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use JsonException;
use Throwable;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class PdoRemotePollVoteRepository implements RemotePollVoteRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function find(string $objectId, string $userId): ?RemotePollVote
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . RemotePollSchema::VOTE_TABLE
			. ' WHERE object_id = :object_id AND user_id = :user_id',
			['object_id' => $objectId, 'user_id' => $userId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function insert(RemotePollVote $vote): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . RemotePollSchema::VOTE_TABLE
				. ' (object_id, user_id, positions, created_at, queued)'
				. ' VALUES (:object_id, :user_id, :positions, :created_at, :queued)',
				[
					'object_id' => $vote->objectId,
					'user_id' => $vote->userId,
					'positions' => json_encode($vote->positions, JSON_THROW_ON_ERROR),
					'created_at' => Time::format($vote->createdAt),
					'queued' => $vote->queued ? 1 : 0,
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The remote poll vote could not be stored.', 0, $exception);
		}
	}

	public function markQueued(string $objectId, string $userId): void
	{
		$this->db->execute(
			'UPDATE ' . RemotePollSchema::VOTE_TABLE
			. ' SET queued = 1 WHERE object_id = :object_id AND user_id = :user_id',
			['object_id' => $objectId, 'user_id' => $userId],
		);
	}

	public function countsFor(string $objectId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT positions FROM ' . RemotePollSchema::VOTE_TABLE . ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		$counts = [];
		foreach ($rows as $row) {
			try {
				$positions = json_decode((string) $row['positions'], true, 16, JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				continue;
			}

			if (!is_array($positions)) {
				continue;
			}

			foreach ($positions as $position) {
				if (is_int($position)) {
					$counts[$position] = ($counts[$position] ?? 0) + 1;
				}
			}
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): RemotePollVote
	{
		try {
			$positions = json_decode((string) $row['positions'], true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$positions = [];
		}

		return new RemotePollVote(
			objectId: (string) $row['object_id'],
			userId: (string) $row['user_id'],
			positions: is_array($positions) ? array_values(array_filter($positions, 'is_int')) : [],
			createdAt: Time::parse((string) $row['created_at']),
			queued: (int) $row['queued'] === 1,
		);
	}
}
