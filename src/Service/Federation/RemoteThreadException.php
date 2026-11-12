<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A remote thread/context request failed with a documented reason.
 */
final class RemoteThreadException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
