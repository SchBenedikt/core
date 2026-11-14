<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use RuntimeException;

/**
 * A cursor that does not belong to this query, is malformed or is too old.
 *
 * The caller answers 400 instead of silently starting a different result
 * set, so a client cannot mistake page two for a fresh feed.
 */
final class InvalidSearchCursorException extends RuntimeException
{
}
