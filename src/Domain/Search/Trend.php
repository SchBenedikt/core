<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use InvalidArgumentException;

/**
 * One hashtag count in a bounded time window.
 */
final readonly class Trend
{
	public function __construct(
		public string $hashtag,
		public int $count,
	) {
		if ($hashtag === '' || $count < 1) {
			throw new InvalidArgumentException('A trend needs a hashtag and a positive count.');
		}
	}

	/**
	 * @return array{hashtag: string, count: int}
	 */
	public function toArray(): array
	{
		return ['hashtag' => $this->hashtag, 'count' => $this->count];
	}
}
