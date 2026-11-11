<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Replay memory in the process, for tests, the demo and tools.
 *
 * Entries expire after a day. That is longer than any sane clock window, so a
 * replayed request is refused while an activity id that reappears weeks later
 * (a different activity, in practice) is not blocked forever.
 */
final class InMemoryActivityLog implements ActivityLog
{
	public const RETENTION_SECONDS = 86400;

	/**
	 * @var array<string, DateTimeImmutable>
	 */
	private array $expiresAt = [];

	public function claim(string $activityId, DateTimeImmutable $now): bool
	{
		$this->prune($now);

		if (isset($this->expiresAt[$activityId])) {
			return false;
		}

		$this->expiresAt[$activityId] = $now->modify('+' . self::RETENTION_SECONDS . ' seconds');

		return true;
	}

	public function release(string $activityId): void
	{
		unset($this->expiresAt[$activityId]);
	}

	private function prune(DateTimeImmutable $now): void
	{
		foreach ($this->expiresAt as $activityId => $expiresAt) {
			if ($expiresAt <= $now) {
				unset($this->expiresAt[$activityId]);
			}
		}
	}
}
