<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/** Stable continuation point for timelines ordered by publication time and ID. */
final readonly class TimelineCursor
{
	public function __construct(
		public DateTimeImmutable $publishedAt,
		public string $id,
	) {
	}
}
