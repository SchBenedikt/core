<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

/**
 * The outcome of one bounded outbox backfill.
 *
 * `items` counts every entry the pages contained up to the item limit;
 * `stored`, `duplicates` and `skipped` sum to it. A skipped entry is an
 * unsupported type, a foreign actor or an activity that failed validation.
 */
final readonly class BackfillReport
{
	public function __construct(
		public int $pages,
		public int $items,
		public int $stored,
		public int $duplicates,
		public int $skipped,
	) {
	}

	/**
	 * @return array{pages: int, items: int, stored: int, duplicates: int, skipped: int}
	 */
	public function toArray(): array
	{
		return [
			'pages' => $this->pages,
			'items' => $this->items,
			'stored' => $this->stored,
			'duplicates' => $this->duplicates,
			'skipped' => $this->skipped,
		];
	}
}
