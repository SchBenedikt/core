<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A refused account move with a stable machine-readable reason.
 *
 * The inbox maps the reason to its rejection code and keeps the activity id
 * unclaimed, so a peer can correct an unreciprocated move and retry it.
 */
final class AccountMoveException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
