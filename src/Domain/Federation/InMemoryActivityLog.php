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
	 * @var array<string, array{expires_at: DateTimeImmutable, digest: ?string}>
	 */
	private array $entries = [];

	public function claim(string $activityId, DateTimeImmutable $now, ?string $digest = null): bool
	{
		$this->prune($now);

		if (isset($this->entries[$activityId])) {
			return false;
		}

		$this->entries[$activityId] = [
			'expires_at' => $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
			'digest' => $digest,
		];

		return true;
	}

	public function digest(string $activityId): ?string
	{
		return $this->entries[$activityId]['digest'] ?? null;
	}

	public function release(string $activityId): void
	{
		unset($this->entries[$activityId]);
	}

	private function prune(DateTimeImmutable $now): void
	{
		foreach ($this->entries as $activityId => $entry) {
			if ($entry['expires_at'] <= $now) {
				unset($this->entries[$activityId]);
			}
		}
	}
}
