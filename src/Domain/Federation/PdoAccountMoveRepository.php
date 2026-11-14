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
use PDOException;

/**
 * The account-move store in SQL.
 *
 * The processed lists are JSON text and are replaced as one row update. A
 * migration batch is therefore either visible with all its progress or not at
 * all; a later retry can safely skip what was already recorded.
 */
final class PdoAccountMoveRepository implements AccountMoveRepository
{
	private const COLUMNS = 'event_id, origin_actor_id, target_actor_id, target_inbox_url,'
		. ' direction, state, processed_outgoing_ids, processed_follower_ids, created_at, updated_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(AccountMove $move): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . AccountMoveSchema::TABLE
			. ' (' . self::COLUMNS . ') VALUES ('
			. ':event_id, :origin_actor_id, :target_actor_id, :target_inbox_url,'
			. ' :direction, :state, :processed_outgoing_ids, :processed_follower_ids,'
				. ' :created_at, :updated_at)',
				$this->parameters($move),
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The account move could not be stored.', 0, $exception);
		}
	}

	public function find(string $eventId): ?AccountMove
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AccountMoveSchema::TABLE . ' WHERE event_id = :event_id',
			['event_id' => $eventId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findOutboundByOrigin(string $originActorId): ?AccountMove
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AccountMoveSchema::TABLE
			. ' WHERE origin_actor_id = :origin_actor_id AND direction = :direction'
			. ' ORDER BY created_at DESC, event_id DESC LIMIT 1',
			['origin_actor_id' => $originActorId, 'direction' => AccountMoveDirection::Outbound->value],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function save(AccountMove $move): void
	{
		$this->db->execute(
			'UPDATE ' . AccountMoveSchema::TABLE . ' SET'
			. ' origin_actor_id = :origin_actor_id,'
			. ' target_actor_id = :target_actor_id, target_inbox_url = :target_inbox_url,'
			. ' direction = :direction, state = :state,'
			. ' processed_outgoing_ids = :processed_outgoing_ids,'
			. ' processed_follower_ids = :processed_follower_ids,'
			. ' created_at = :created_at, updated_at = :updated_at'
			. ' WHERE event_id = :event_id',
			$this->parameters($move),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(AccountMove $move): array
	{
		return [
			'event_id' => $move->eventId,
			'origin_actor_id' => $move->originActorId,
			'target_actor_id' => $move->targetActorId,
			'target_inbox_url' => $move->targetInboxUrl,
			'direction' => $move->direction->value,
			'state' => $move->state->value,
			'processed_outgoing_ids' => json_encode($move->processedOutgoingIds, JSON_THROW_ON_ERROR),
			'processed_follower_ids' => json_encode($move->processedFollowerIds, JSON_THROW_ON_ERROR),
			'created_at' => Time::format($move->createdAt),
			'updated_at' => Time::format($move->updatedAt),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): AccountMove
	{
		return new AccountMove(
			eventId: (string) $row['event_id'],
			originActorId: (string) $row['origin_actor_id'],
			targetActorId: (string) $row['target_actor_id'],
			targetInboxUrl: (string) $row['target_inbox_url'],
			direction: AccountMoveDirection::from((string) $row['direction']),
			state: AccountMoveState::from((string) $row['state']),
			processedOutgoingIds: $this->decodeIds($row['processed_outgoing_ids'] ?? null),
			processedFollowerIds: $this->decodeIds($row['processed_follower_ids'] ?? null),
			createdAt: Time::parse((string) $row['created_at']),
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}

	/**
	 * @return list<string>
	 */
	private function decodeIds(mixed $value): array
	{
		if (!is_string($value) || $value === '') {
			return [];
		}

		$decoded = json_decode($value, true);

		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}
}
