<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use RuntimeException;

/**
 * A remote lookup failed for a reason the search response can report as a
 * partial result. The reason is stable and never contains a URL with
 * credentials or a response body.
 */
final class RemoteSearchException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
