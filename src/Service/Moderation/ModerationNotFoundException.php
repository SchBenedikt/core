<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Moderation;

use RuntimeException;

final class ModerationNotFoundException extends RuntimeException
{
	public function __construct(string $id)
	{
		parent::__construct('No moderation record exists for "' . $id . '".');
	}
}
