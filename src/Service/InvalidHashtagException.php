<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A tag that cannot be normalized to a hashtag.
 */
final class InvalidHashtagException extends RuntimeException
{
	public function __construct(
		public readonly string $tag,
	) {
		parent::__construct('Not a valid hashtag: "' . $tag . '". Use letters, digits or underscores.');
	}
}
