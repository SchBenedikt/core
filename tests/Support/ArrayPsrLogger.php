<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps log records so a test can assert that a failure was reported.
 */
final class ArrayPsrLogger extends AbstractLogger
{
	/**
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $records = [];

	/**
	 * @param mixed[] $context
	 */
	public function log($level, string|Stringable $message, array $context = []): void
	{
		$this->records[] = [
			'level' => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		];
	}

	/**
	 * @return list<string>
	 */
	public function levels(): array
	{
		return array_map(static fn (array $record): string => $record['level'], $this->records);
	}
}
