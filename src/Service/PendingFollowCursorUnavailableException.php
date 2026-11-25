<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/** The storage adapter does not support stable keyset paging for requests. */
final class PendingFollowCursorUnavailableException extends RuntimeException
{
}
