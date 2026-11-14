<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Moderation;

use RuntimeException;

/**
 * Another moderator claimed the same report or held post first.
 */
final class ModerationConflictException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('The moderation item "' . $id . '" is being reviewed by somebody else.');
	}
}
