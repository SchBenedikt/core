<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * One label emitted with an event.
 *
 * `labelerDid` is kept separately from the value because a blocked labeler
 * must be refused even when the label itself sounds harmless. A negation only
 * reverses a previous label; it never grants access on its own.
 */
final readonly class AtprotoLabel
{
	public const MAX_VALUE_LENGTH = 64;

	public function __construct(
		public string $value,
		public string $labelerDid,
		public bool $negated = false,
	) {
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol label needs a bounded value.');
		}

		if (!AtprotoIdentifiers::isDid($labelerDid)) {
			throw new InvalidArgumentException('An AT Protocol label needs a valid labeler DID.');
		}
	}
}
