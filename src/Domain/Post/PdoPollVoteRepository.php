<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;

/**
 * Per-user poll votes in SQL.
 *
 * One row belongs to one voter and one poll; the positions string is a
 * canonical comma-separated list of zero-based option indexes. The insert and
 * both counter updates share a transaction, so a web request can never expose
 * a vote row whose counts were not advanced (or the reverse).
 */
final class PdoPollVoteRepository implements PollVoteRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function find(string $postId, string $userId): ?PollVote
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT post_id, user_id, positions, created_at FROM ' . PollSchema::VOTE_TABLE
				. ' WHERE post_id = :post_id AND user_id = :user_id',
				['post_id' => $postId, 'user_id' => $userId],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The poll vote table could not be read.', 0, $exception);
		}

		return $row === null ? null : $this->hydrate($row);
	}

	public function findForPosts(string $userId, array $postIds): array
	{
		if ($postIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = ['user_id' => $userId];
		foreach (array_values($postIds) as $index => $postId) {
			$name = 'post_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $postId;
		}

		try {
			$rows = $this->db->fetchAll(
				'SELECT post_id, user_id, positions, created_at FROM ' . PollSchema::VOTE_TABLE
				. ' WHERE user_id = :user_id AND post_id IN (' . implode(', ', $placeholders) . ')',
				$parameters,
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The poll vote table could not be read.', 0, $exception);
		}

		$votes = [];
		foreach ($rows as $row) {
			$vote = $this->hydrate($row);
			$votes[$vote->postId] = $vote;
		}

		return $votes;
	}

	public function insert(PollVote $vote): void
	{
		try {
			$this->db->transaction(function () use ($vote): void {
				$this->db->execute(
					'INSERT INTO ' . PollSchema::VOTE_TABLE
					. ' (post_id, user_id, positions, created_at)'
					. ' VALUES (:post_id, :user_id, :positions, :created_at)',
					[
						'post_id' => $vote->postId,
						'user_id' => $vote->userId,
						'positions' => implode(',', $vote->positions),
						'created_at' => Time::format($vote->createdAt),
					],
				);

				foreach ($vote->positions as $position) {
					$updated = $this->db->execute(
						'UPDATE ' . PollSchema::OPTION_TABLE
						. ' SET votes_count = votes_count + 1'
						. ' WHERE post_id = :post_id AND position = :position',
						['post_id' => $vote->postId, 'position' => $position],
					);
					if ($updated !== 1) {
						throw new DatabaseException('The poll option no longer exists.');
					}
				}

				$updated = $this->db->execute(
					'UPDATE ' . PollSchema::TABLE
					. ' SET votes_count = votes_count + :votes, voters_count = voters_count + 1'
					. ' WHERE post_id = :post_id',
					[
						'post_id' => $vote->postId,
						'votes' => count($vote->positions),
					],
				);
				if ($updated !== 1) {
					throw new DatabaseException('The poll no longer exists.');
				}
			});
		} catch (PDOException $exception) {
			throw new DatabaseException('The poll vote could not be stored.', 0, $exception);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): PollVote
	{
		$positions = array_values(array_map(
			static fn (string $position): int => (int) $position,
			explode(',', (string) $row['positions']),
		));

		return new PollVote(
			postId: (string) $row['post_id'],
			userId: (string) $row['user_id'],
			positions: $positions,
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
