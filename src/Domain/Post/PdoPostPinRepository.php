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

final class PdoPostPinRepository implements PostPinRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(PostPin $pin): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . PostPinSchema::TABLE
				. ' (owner_id, post_id, position, pinned_at) VALUES (:owner_id, :post_id, :position, :pinned_at)',
				[
					'owner_id' => $pin->ownerId,
					'post_id' => $pin->postId,
					'position' => $pin->position,
					'pinned_at' => Time::format($pin->pinnedAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The post could not be pinned.', 0, $exception);
		}
	}

	public function find(string $ownerId, string $postId): ?PostPin
	{
		$row = $this->db->fetchOne(
			'SELECT owner_id, post_id, position, pinned_at FROM ' . PostPinSchema::TABLE
			. ' WHERE owner_id = :owner_id AND post_id = :post_id',
			['owner_id' => $ownerId, 'post_id' => $postId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function forOwner(string $ownerId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT owner_id, post_id, position, pinned_at FROM ' . PostPinSchema::TABLE
			. ' WHERE owner_id = :owner_id ORDER BY position ASC',
			['owner_id' => $ownerId],
		);

		return array_map(fn (array $row): PostPin => $this->hydrate($row), $rows);
	}

	public function count(string $ownerId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostPinSchema::TABLE . ' WHERE owner_id = :owner_id',
			['owner_id' => $ownerId],
		);
	}

	public function remove(string $ownerId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . PostPinSchema::TABLE . ' WHERE owner_id = :owner_id AND post_id = :post_id',
			['owner_id' => $ownerId, 'post_id' => $postId],
		) > 0;
	}

	public function removeForPost(string $postId): void
	{
		$this->db->execute(
			'DELETE FROM ' . PostPinSchema::TABLE . ' WHERE post_id = :post_id',
			['post_id' => $postId],
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): PostPin
	{
		return new PostPin(
			(string) $row['owner_id'],
			(string) $row['post_id'],
			(int) $row['position'],
			Time::parse((string) $row['pinned_at']),
		);
	}
}
