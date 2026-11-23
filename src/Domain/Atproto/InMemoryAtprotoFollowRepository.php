<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\DatabaseException;

final class InMemoryAtprotoFollowRepository implements AtprotoFollowRepository
{
	/** @var array<string, AtprotoFollow> */
	private array $follows = [];

	public function findForSubject(string $localUserId, string $subjectDid): ?AtprotoFollow
	{
		return $this->follows[$this->key($localUserId, $subjectDid)] ?? null;
	}

	public function forUser(string $localUserId): array
	{
		$follows = array_values(array_filter(
			$this->follows,
			static fn (AtprotoFollow $follow): bool => $follow->localUserId === $localUserId,
		));
		usort($follows, static fn (AtprotoFollow $a, AtprotoFollow $b): int => (
			$b->followedAt <=> $a->followedAt) ?: strcmp($b->subjectDid, $a->subjectDid));

		return $follows;
	}

	public function save(AtprotoFollow $follow): void
	{
		$key = $this->key($follow->localUserId, $follow->subjectDid);
		foreach ($this->follows as $stored) {
			if ($stored->localUserId !== $follow->localUserId && $stored->accountDid === $follow->accountDid
				&& $stored->rkey === $follow->rkey) {
				throw new DatabaseException('The AT Protocol follow record key is already used.');
			}
		}
		$this->follows[$key] = $follow;
	}

	public function remove(string $localUserId, string $subjectDid): bool
	{
		$key = $this->key($localUserId, $subjectDid);
		if (!isset($this->follows[$key])) {
			return false;
		}
		unset($this->follows[$key]);
		return true;
	}

	private function key(string $localUserId, string $subjectDid): string
	{
		return $localUserId . "\0" . $subjectDid;
	}
}
