<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * Removes account rows and blobs again after a write that was already in
 * flight when the account was frozen.
 *
 * The HTTP gate and the job worker check before doing work, but a request or
 * handler that passed that check just before the freeze can commit after the
 * deletion. The sweep is the final, idempotent pass that closes that window.
 */
interface AccountDeletionSweeper
{
	public function sweep(string $userId): void;
}
