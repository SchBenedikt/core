<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PDOException;

use function array_fill_keys;
use function array_values;
use function implode;

/**
 * Federated reactions in SQL.
 *
 * The unique key is the identity, so two concurrent deliveries of one Like
 * collide in the database instead of both counting. Reads never trust the
 * stored id for identity: they query the same columns the unique key uses.
 */
final class PdoReactionRepository implements ReactionRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Reaction $reaction): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . ReactionSchema::TABLE
				. ' (id, direction, subject_id, object_id, local_post_id, kind, emoji, icon_url, activity_id, created_at)'
				. ' VALUES (:id, :direction, :subject_id, :object_id, :local_post_id, :kind, :emoji, :icon_url, :activity_id, :created_at)',
				[
					'id' => $reaction->id(),
					'direction' => $reaction->direction->value,
					'subject_id' => $reaction->subjectId,
					'object_id' => $reaction->objectId,
					'local_post_id' => $reaction->localPostId,
					'kind' => $reaction->kind->value,
					'emoji' => $reaction->emojiKey(),
					'icon_url' => $reaction->iconUrl,
					'activity_id' => $reaction->activityId,
					'created_at' => Time::format($reaction->createdAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The reaction could not be stored.', 0, $exception);
		}
	}

	public function remove(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): bool {
		return $this->db->execute(
			'DELETE FROM ' . ReactionSchema::TABLE
			. ' WHERE direction = :direction AND subject_id = :subject_id AND object_id = :object_id'
			. ' AND kind = :kind AND emoji = :emoji',
			[
				'direction' => $direction->value,
				'subject_id' => $subjectId,
				'object_id' => $objectId,
				'kind' => $kind->value,
				'emoji' => $emoji ?? '',
			],
		) > 0;
	}

	public function find(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): ?Reaction {
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . ReactionSchema::TABLE
			. ' WHERE direction = :direction AND subject_id = :subject_id AND object_id = :object_id'
			. ' AND kind = :kind AND emoji = :emoji',
			[
				'direction' => $direction->value,
				'subject_id' => $subjectId,
				'object_id' => $objectId,
				'kind' => $kind->value,
				'emoji' => $emoji ?? '',
			],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByActivityId(string $activityId): ?Reaction
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . ReactionSchema::TABLE . ' WHERE activity_id = :activity_id',
			['activity_id' => $activityId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function countsForPosts(array $localPostIds): array
	{
		$counts = array_fill_keys(array_values($localPostIds), ['likes' => 0, 'emojis' => []]);
		if ($localPostIds === []) {
			return $counts;
		}

		[$placeholders, $parameters] = $this->placeholders($localPostIds);
		$rows = $this->db->fetchAll(
			'SELECT local_post_id, kind, emoji, COUNT(*) AS total FROM ' . ReactionSchema::TABLE
			. ' WHERE direction = :direction AND local_post_id IN (' . implode(', ', $placeholders) . ')'
			. ' GROUP BY local_post_id, kind, emoji',
			$parameters + ['direction' => ReactionDirection::In->value],
		);

		foreach ($rows as $row) {
			$postId = (string) $row['local_post_id'];
			if (!isset($counts[$postId])) {
				continue;
			}

			$total = (int) $row['total'];
			if ($row['kind'] === ReactionKind::Like->value) {
				$counts[$postId]['likes'] = $total;
				continue;
			}

			$counts[$postId]['emojis'][(string) $row['emoji']] = $total;
		}

		return $counts;
	}

	public function forSubject(ReactionDirection $direction, string $subjectId, array $objectIds): array
	{
		$rows = array_fill_keys(array_values($objectIds), []);
		if ($objectIds === []) {
			return $rows;
		}

		[$placeholders, $parameters] = $this->placeholders($objectIds);
		$stored = $this->db->fetchAll(
			'SELECT * FROM ' . ReactionSchema::TABLE
			. ' WHERE direction = :direction AND subject_id = :subject_id'
			. ' AND object_id IN (' . implode(', ', $placeholders) . ')'
			. ' ORDER BY created_at ASC, id ASC',
			$parameters + [
				'direction' => $direction->value,
				'subject_id' => $subjectId,
			],
		);

		foreach ($stored as $row) {
			$reaction = $this->hydrate($row);
			$rows[$reaction->objectId][] = $reaction;
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Reaction
	{
		return new Reaction(
			direction: ReactionDirection::from((string) $row['direction']),
			subjectId: (string) $row['subject_id'],
			objectId: (string) $row['object_id'],
			localPostId: $row['local_post_id'] === null ? null : (string) $row['local_post_id'],
			kind: ReactionKind::from((string) $row['kind']),
			emoji: (string) $row['emoji'] === '' ? null : (string) $row['emoji'],
			iconUrl: $row['icon_url'] === null ? null : (string) $row['icon_url'],
			activityId: (string) $row['activity_id'],
			createdAt: new DateTimeImmutable((string) $row['created_at']),
		);
	}

	/**
	 * @param list<string> $values
	 *
	 * @return array{0: list<string>, 1: array<string, string>}
	 */
	private function placeholders(array $values): array
	{
		$placeholders = [];
		$parameters = [];
		foreach (array_values($values) as $index => $value) {
			$name = 'value_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $value;
		}

		return [$placeholders, $parameters];
	}
}
