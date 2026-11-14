<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of FEP-044f quote grants.
 *
 * The id is derived from the quoted and quoting object, so a repeated
 * QuoteRequest finds the existing row instead of issuing a second grant.
 */
interface QuoteGrantRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(QuoteGrant $grant): void;

	public function find(string $id): ?QuoteGrant;

	/**
	 * Moves a grant to rejected or revoked. An already final grant keeps its
	 * state, so a replay cannot turn a revocation back into an acceptance.
	 *
	 * @return bool whether the state changed
	 */
	public function close(string $id, QuoteState $state, DateTimeImmutable $at): bool;
}
