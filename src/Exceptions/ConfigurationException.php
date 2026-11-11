<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Exceptions;

use RuntimeException;

/**
 * The instance is not configured well enough to do what was asked.
 */
final class ConfigurationException extends RuntimeException
{
}
