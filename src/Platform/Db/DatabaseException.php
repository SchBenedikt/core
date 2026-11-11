<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Db;

use RuntimeException;

/**
 * Raised when a database operation cannot be completed.
 */
class DatabaseException extends RuntimeException
{
}
