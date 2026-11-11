<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Security;

use RuntimeException;

/**
 * A secret could not be sealed or opened.
 */
final class SecretBoxException extends RuntimeException
{
}
