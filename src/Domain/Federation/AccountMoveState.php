<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * The durable progress of one account move.
 *
 * `pending` means the move is recorded but at least one follow edge still has
 * to be migrated; `completed` means every edge was processed. A failed batch
 * leaves the row pending, so a worker retry resumes instead of starting over.
 */
enum AccountMoveState: string
{
	case Pending = 'pending';
	case Completed = 'completed';
}
