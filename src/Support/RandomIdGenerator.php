<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Support;

/**
 * 96 bits of randomness, hex encoded.
 *
 * Post and job ids appear in URLs and are not guessable, so they are drawn
 * from `random_bytes()` rather than from a counter.
 */
final class RandomIdGenerator implements IdGenerator
{
	public const BYTES = 12;

	public function generate(): string
	{
		return bin2hex(random_bytes(self::BYTES));
	}
}
