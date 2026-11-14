<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A move job or host call named a row that does not exist.
 *
 * This is a terminal worker error: retrying the same id cannot create the row.
 */
final class AccountMoveNotFoundException extends RuntimeException
{
}
