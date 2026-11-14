<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_values;

final class InMemoryQuoteGrantRepository implements QuoteGrantRepository
{
	/**
	 * @var array<string, QuoteGrant>
	 */
	private array $grants = [];

	public function insert(QuoteGrant $grant): void
	{
		if (isset($this->grants[$grant->id])) {
			throw new DatabaseException('The quote grant id already exists.');
		}

		foreach ($this->grants as $existing) {
			if ($existing->interactingObjectId === $grant->interactingObjectId) {
				throw new DatabaseException('This object already has a quote grant.');
			}
		}

		$this->grants[$grant->id] = $grant;
	}

	public function find(string $id): ?QuoteGrant
	{
		return $this->grants[$id] ?? null;
	}

	public function close(string $id, QuoteState $state, DateTimeImmutable $at): bool
	{
		$grant = $this->grants[$id] ?? null;
		if ($grant === null || $grant->state->isFinal()) {
			return false;
		}

		$this->grants[$id] = $grant->withState($state, $at);

		return true;
	}

	/**
	 * @return list<QuoteGrant>
	 */
	public function all(): array
	{
		return array_values($this->grants);
	}
}
