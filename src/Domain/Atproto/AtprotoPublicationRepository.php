<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Idempotency records for AT Protocol publishing.
 *
 * A publication belongs to exactly one local account and one local post. The
 * repository does not store access tokens; those stay in the host.
 */
interface AtprotoPublicationRepository
{
	public function findForPost(string $localUserId, string $postId): ?AtprotoPublication;

	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the pair is already stored
	 */
	public function insert(AtprotoPublication $publication): void;

	/**
	 * Removes the local idempotency record. The remote record is not deleted;
	 * host cleanup must use the AT URI separately.
	 *
	 * @return bool whether a row was removed
	 */
	public function removeForPost(string $localUserId, string $postId): bool;
}
