<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use Psr\Log\AbstractLogger;

use function is_string;

/**
 * A minimal PSR-3 logger that keeps records in memory.
 */
final class RecordingLogger extends AbstractLogger
{
	/**
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $records = [];

	/**
	 * @param mixed                $level
	 * @param string|\Stringable   $message
	 * @param array<string, mixed> $context
	 */
	public function log($level, $message, array $context = []): void
	{
		$this->records[] = [
			'level' => is_string($level) ? $level : (string) $level,
			'message' => is_string($message) ? $message : (string) $message,
			'context' => $context,
		];
	}

	/**
	 * @return list<string>
	 */
	public function reasons(): array
	{
		$reasons = [];
		foreach ($this->records as $record) {
			$reason = $record['context']['reason'] ?? null;
			if (is_string($reason)) {
				$reasons[] = $reason;
			}
		}

		return $reasons;
	}
}
