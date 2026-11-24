<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

interface AtprotoInteractionRepository
{
	public function find(string $localUserId, string $accountDid, string $postId, string $kind): ?AtprotoInteractionRecord;

	/** @throws \AlohaSocial\Core\Platform\Db\DatabaseException */
	public function insert(AtprotoInteractionRecord $record): void;

	public function remove(string $localUserId, string $accountDid, string $postId, string $kind): bool;

	/** @return list<AtprotoInteractionRecord> */
	public function forUser(string $localUserId): array;
}
