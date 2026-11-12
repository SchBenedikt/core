<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use RuntimeException;

/**
 * The export cannot be validated at all: unreadable file, invalid JSON or a
 * missing/wrong structural field. This is distinct from a parity violation,
 * because a malformed export is a tooling/setup error, not evidence that the
 * migrated data changed identity.
 */
final class InvalidMigrationDatasetException extends RuntimeException
{
}
