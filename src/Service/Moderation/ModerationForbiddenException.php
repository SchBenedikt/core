<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Moderation;

use RuntimeException;

/**
 * The caller is not a moderator according to the host's authorization port.
 */
final class ModerationForbiddenException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('This action requires an instance moderator.');
	}
}
