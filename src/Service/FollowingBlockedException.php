<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

final class FollowingBlockedException extends RuntimeException
{
	public function __construct(string $actorId)
	{
		parent::__construct('The remote actor has blocked this local account: ' . $actorId);
	}
}
