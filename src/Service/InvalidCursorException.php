<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A `before` cursor that is not a canonical timestamp.
 */
final class InvalidCursorException extends RuntimeException
{
	public function __construct(
		public readonly string $cursor,
	) {
		parent::__construct('Not a valid cursor: "' . $cursor . '". Expected a timestamp like 2026-11-11T18:14:00Z.');
	}
}
