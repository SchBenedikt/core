<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use RuntimeException;

/**
 * A list was asked for that does not exist or belongs to another account.
 */
final class AccountListNotFoundException extends RuntimeException
{
	public function __construct(
		public readonly string $listId,
	) {
		parent::__construct('Account list not found: ' . $listId);
	}
}
