<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

interface AtprotoFollowRepository
{
	public function findForSubject(string $localUserId, string $subjectDid): ?AtprotoFollow;

	/** @return list<AtprotoFollow> */
	public function forUser(string $localUserId): array;

	public function save(AtprotoFollow $follow): void;

	public function remove(string $localUserId, string $subjectDid): bool;
}
