<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * Durable actor/domain policy actions.
 *
 * Reads only ever return active rows; reversal is a stamp, not a delete, so
 * the decision history stays inspectable.
 */
interface ModerationActionRepository
{
	public function insert(ModerationAction $action): void;

	public function find(string $id): ?ModerationAction;

	public function findActive(ModerationActionKind $kind, string $target): ?ModerationAction;

	/**
	 * Newest active actions first.
	 *
	 * @return list<ModerationAction>
	 */
	public function active(?ModerationActionKind $kind = null, int $limit = 100): array;

	/**
	 * Newest actions for one target, including reversed ones.
	 *
	 * @return list<ModerationAction>
	 */
	public function historyFor(string $target, int $limit = 100): array;

	/**
	 * active -> reversed. False when it was already reversed or is missing.
	 */
	public function reverse(string $id, string $moderatorId, string $note, \DateTimeImmutable $at): bool;

	public function isDomainBlocked(string $domain): bool;

	public function isActorRestricted(string $actorId): bool;
}
