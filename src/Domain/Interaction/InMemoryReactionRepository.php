<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

use AlohaSocial\Core\Platform\Db\DatabaseException;

use function array_fill_keys;
use function array_values;

/**
 * Reactions in memory, for tests, the demo and tools.
 */
final class InMemoryReactionRepository implements ReactionRepository
{
	/**
	 * @var array<string, Reaction>
	 */
	private array $rows = [];

	public function insert(Reaction $reaction): void
	{
		$id = $reaction->id();
		if (isset($this->rows[$id])) {
			throw new DatabaseException('The reaction is already stored.');
		}

		$this->rows[$id] = $reaction;
	}

	public function remove(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): bool {
		$id = $this->idFor($direction, $subjectId, $objectId, $kind, $emoji);
		if (!isset($this->rows[$id])) {
			return false;
		}

		unset($this->rows[$id]);

		return true;
	}

	public function find(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): ?Reaction {
		return $this->rows[$this->idFor($direction, $subjectId, $objectId, $kind, $emoji)] ?? null;
	}

	public function findByActivityId(string $activityId): ?Reaction
	{
		foreach ($this->rows as $row) {
			if ($row->activityId === $activityId) {
				return $row;
			}
		}

		return null;
	}

	public function countsForPosts(array $localPostIds): array
	{
		$counts = array_fill_keys(array_values($localPostIds), ['likes' => 0, 'emojis' => []]);
		foreach ($this->rows as $row) {
			if ($row->direction !== ReactionDirection::In || $row->localPostId === null) {
				continue;
			}

			if (!isset($counts[$row->localPostId])) {
				continue;
			}

			if ($row->kind === ReactionKind::Like) {
				$counts[$row->localPostId]['likes']++;
				continue;
			}

			$key = $row->emojiKey();
			$counts[$row->localPostId]['emojis'][$key] = ($counts[$row->localPostId]['emojis'][$key] ?? 0) + 1;
		}

		return $counts;
	}

	public function forSubject(ReactionDirection $direction, string $subjectId, array $objectIds): array
	{
		$rows = array_fill_keys(array_values($objectIds), []);
		foreach ($this->rows as $row) {
			if ($row->direction !== $direction || $row->subjectId !== $subjectId) {
				continue;
			}

			if (!isset($rows[$row->objectId])) {
				continue;
			}

			$rows[$row->objectId][] = $row;
		}

		return $rows;
	}

	private function idFor(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): string {
		return Reaction::idFor($direction, $subjectId, $objectId, $kind, $emoji);
	}
}
