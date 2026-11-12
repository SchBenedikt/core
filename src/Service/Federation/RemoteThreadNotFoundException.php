<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * The target remote post is missing, deleted, not followed by the reader or
 * hidden by that reader's block.
 *
 * The handler maps all four cases to the same 404 so the route does not leak
 * whether a remote post exists for another account.
 */
final class RemoteThreadNotFoundException extends RuntimeException
{
}
