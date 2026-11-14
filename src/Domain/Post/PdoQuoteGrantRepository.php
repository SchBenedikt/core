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
use DateTimeImmutable;
use Throwable;

final class PdoQuoteGrantRepository implements QuoteGrantRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(QuoteGrant $grant): void
	{
		try {
			$this->insertRow($grant);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The quote grant could not be stored.', 0, $exception);
		}
	}

	private function insertRow(QuoteGrant $grant): void
	{
		$this->db->execute(
			'INSERT INTO ' . QuoteGrantSchema::TABLE
			. ' (id, quoted_object_id, interacting_object_id, actor_id, quoted_actor_id, state, created_at, updated_at)'
			. ' VALUES (:id, :quoted_object_id, :interacting_object_id, :actor_id, :quoted_actor_id, :state, :created_at, :updated_at)',
			[
				'id' => $grant->id,
				'quoted_object_id' => $grant->quotedObjectId,
				'interacting_object_id' => $grant->interactingObjectId,
				'actor_id' => $grant->actorId,
				'quoted_actor_id' => $grant->quotedActorId,
				'state' => $grant->state->value,
				'created_at' => Time::format($grant->createdAt),
				'updated_at' => Time::format($grant->updatedAt),
			],
		);
	}

	public function find(string $id): ?QuoteGrant
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . QuoteGrantSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function close(string $id, QuoteState $state, DateTimeImmutable $at): bool
	{
		$grant = $this->find($id);
		if ($grant === null || $grant->state->isFinal()) {
			return false;
		}

		$this->db->execute(
			'UPDATE ' . QuoteGrantSchema::TABLE
			. ' SET state = :state, updated_at = :updated_at'
			. " WHERE id = :id AND state NOT IN ('rejected', 'revoked')",
			[
				'id' => $id,
				'state' => $state->value,
				'updated_at' => Time::format($at),
			],
		);

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): QuoteGrant
	{
		return new QuoteGrant(
			id: (string) $row['id'],
			quotedObjectId: (string) $row['quoted_object_id'],
			interactingObjectId: (string) $row['interacting_object_id'],
			actorId: (string) $row['actor_id'],
			quotedActorId: (string) $row['quoted_actor_id'],
			state: QuoteState::from((string) $row['state']),
			createdAt: Time::parse((string) $row['created_at']),
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}
}
