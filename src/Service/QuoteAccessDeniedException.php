<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

final class QuoteAccessDeniedException extends RuntimeException
{
	public function __construct(public readonly string $grantId, public readonly string $actorId)
	{
		parent::__construct('Only the quoted post author can revoke this quote authorization.');
	}
}
