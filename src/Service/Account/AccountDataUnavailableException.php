<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Account;

use RuntimeException;

/**
 * The host did not provide the SQL account-data adapter.
 *
 * The in-memory demo has no durable account state to export or erase, so the
 * routes answer 503 rather than pretending an empty archive is complete.
 */
final class AccountDataUnavailableException extends RuntimeException
{
}
