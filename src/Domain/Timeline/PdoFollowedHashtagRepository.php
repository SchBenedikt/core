<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PDOException;

use function array_map;

/**
 * Private followed hashtags in SQL.
 *
 * Every statement includes the owner, so a guessed tag cannot read or change
 * another account's follows. The composite primary key is the idempotency
 * boundary; a duplicate insert is reported as a no-op only after the row is
 * confirmed to exist.
 */
final class PdoFollowedHashtagRepository implements FollowedHashtagRepository
{
	private const COLUMNS = 'user_id, hashtag, created_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function follow(string $accountId, string $tag, DateTimeImmutable $createdAt): bool
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . FollowedHashtagSchema::TABLE
				. ' (' . self::COLUMNS . ') VALUES (:user_id, :hashtag, :created_at)',
				[
					'user_id' => $accountId,
					'hashtag' => $tag,
					'created_at' => Time::format($createdAt),
				],
			);

			return true;
		} catch (PDOException $exception) {
			if ($this->find($accountId, $tag) !== null) {
				return false;
			}

			throw new DatabaseException('The followed hashtag could not be stored.', 0, $exception);
		}
	}

	public function unfollow(string $accountId, string $tag): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FollowedHashtagSchema::TABLE
			. ' WHERE user_id = :user_id AND hashtag = :hashtag',
			['user_id' => $accountId, 'hashtag' => $tag],
		) > 0;
	}

	public function find(string $accountId, string $tag): ?FollowedHashtag
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowedHashtagSchema::TABLE
			. ' WHERE user_id = :user_id AND hashtag = :hashtag',
			['user_id' => $accountId, 'hashtag' => $tag],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function page(
		string $accountId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeTag = null,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . FollowedHashtagSchema::TABLE
			. ' WHERE user_id = :user_id';
		$parameters = ['user_id' => $accountId];

		if ($before !== null) {
			if ($beforeTag === null) {
				$sql .= ' AND created_at < :before';
			} else {
				$sql .= ' AND (created_at < :before OR (created_at = :before AND hashtag < :before_tag))';
				$parameters['before_tag'] = $beforeTag;
			}
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at DESC, hashtag DESC LIMIT ' . max(1, min(100, $limit));

		return array_map(
			fn (array $row): FollowedHashtag => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function count(string $accountId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowedHashtagSchema::TABLE . ' WHERE user_id = :user_id',
			['user_id' => $accountId],
		);
	}

	public function tags(string $accountId): array
	{
		return array_map(
			static fn (FollowedHashtag $follow): string => $follow->tag,
			$this->page($accountId, 100),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): FollowedHashtag
	{
		return new FollowedHashtag(
			accountId: (string) $row['user_id'],
			tag: (string) $row['hashtag'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
