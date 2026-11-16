<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * A cheap read of the account deletion tombstone for write paths.
 *
 * Kept separate from the full account-data port so the HTTP gate and the job
 * worker do not need the export/erasure surface.
 */
interface AccountWriteGuard
{
	/**
	 * Null when the account may write, otherwise the HTTP status that the
	 * API boundary should return (403 while frozen, 410 once erased).
	 */
	public function accountWriteStatus(string $userId): ?int;
}
