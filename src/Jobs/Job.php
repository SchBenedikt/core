<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use DateTimeImmutable;

/**
 * One unit of background work.
 *
 * The payload is plain data: it survives a restart, and a worker in another
 * process can pick it up. Jobs are retried by the queue, so a handler must be
 * safe to run twice.
 */
final readonly class Job
{
	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct(
		public string $id,
		public string $type,
		public array $payload,
		public DateTimeImmutable $availableAt,
		public int $attempts = 0,
		public ?JobSubject $subject = null,
	) {
	}
}
