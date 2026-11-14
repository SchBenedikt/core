<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use InvalidArgumentException;

/**
 * A record that must not enter the discovery index.
 *
 * This is an invalid-argument exception rather than a storage error: a
 * caller that tries to index a followers-only or direct object has made a
 * programming error, not hit a temporary database problem.
 */
final class InvalidSearchRecordException extends InvalidArgumentException
{
}
