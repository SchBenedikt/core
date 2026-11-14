<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A pending follow request that does not exist, belongs to another account or
 * was already answered. The handler maps all three to one 404 so a caller
 * cannot probe another account's request ids.
 */
final class FollowRequestNotFoundException extends RuntimeException
{
}
