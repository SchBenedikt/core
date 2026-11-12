<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use RuntimeException;

/**
 * The PDS did not confirm a record write.
 *
 * The message must stay free of credentials and response bodies; only the
 * status and a local explanation are safe to expose.
 */
final class AtprotoPublishFailedException extends RuntimeException
{
	public function __construct(
		public readonly int $status,
		string $message,
	) {
		parent::__construct($message);
	}
}
