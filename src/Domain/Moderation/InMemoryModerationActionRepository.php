<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use DateTimeImmutable;

/**
 * Actor/domain policy storage for tests and the in-memory assembly.
 */
final class InMemoryModerationActionRepository implements ModerationActionRepository
{
	/**
	 * @var array<string, ModerationAction>
	 */
	private array $actions = [];

	public function insert(ModerationAction $action): void
	{
		$this->actions[$action->id] = $action;
	}

	public function find(string $id): ?ModerationAction
	{
		return $this->actions[$id] ?? null;
	}

	public function findActive(ModerationActionKind $kind, string $target): ?ModerationAction
	{
		foreach ($this->actions as $action) {
			if ($action->isActive() && $action->kind === $kind && $action->target === $target) {
				return $action;
			}
		}

		return null;
	}

	public function active(?ModerationActionKind $kind = null, int $limit = 100): array
	{
		$actions = array_values(array_filter(
			$this->actions,
			static fn (ModerationAction $action): bool => $action->isActive()
				&& ($kind === null || $action->kind === $kind),
		));

		usort($actions, static function (ModerationAction $a, ModerationAction $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($actions, 0, max(1, $limit));
	}

	public function historyFor(string $target, int $limit = 100): array
	{
		$actions = array_values(array_filter(
			$this->actions,
			static fn (ModerationAction $action): bool => $action->target === $target,
		));

		usort($actions, static function (ModerationAction $a, ModerationAction $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($actions, 0, max(1, $limit));
	}

	public function reverse(string $id, string $moderatorId, string $note, DateTimeImmutable $at): bool
	{
		$action = $this->actions[$id] ?? null;
		if ($action === null || !$action->isActive()) {
			return false;
		}

		$this->actions[$id] = new ModerationAction(
			id: $action->id,
			kind: $action->kind,
			target: $action->target,
			reportId: $action->reportId,
			moderatorId: $action->moderatorId,
			note: $action->note,
			createdAt: $action->createdAt,
			reversedAt: $at,
			reversedBy: $moderatorId,
			reversalNote: $note,
			publicComment: $action->publicComment,
		);

		return true;
	}

	public function isDomainBlocked(string $domain): bool
	{
		return $this->findActive(ModerationActionKind::BlockDomain, $domain) !== null;
	}

	public function isActorRestricted(string $actorId): bool
	{
		return $this->findActive(ModerationActionKind::SilenceActor, $actorId) !== null
			|| $this->findActive(ModerationActionKind::SuspendActor, $actorId) !== null;
	}
}
