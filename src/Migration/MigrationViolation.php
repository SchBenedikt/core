<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use JsonException;

use function is_bool;
use function is_int;
use function is_string;

/**
 * One mismatch found while comparing a migration export with the core's
 * derived federation identity.
 *
 * The record is deliberately a path (`following[2]`) rather than a database
 * row id: the export is the only input and the host needs to be able to find
 * the same entry without the validator knowing the host's schema.
 */
final readonly class MigrationViolation
{
	public function __construct(
		public string $record,
		public string $field,
		public string $message,
		public mixed $expected = null,
		public mixed $actual = null,
	) {
	}

	/**
	 * @return array{record: string, field: string, message: string, expected: mixed, actual: mixed}
	 */
	public function toArray(): array
	{
		return [
			'record' => $this->record,
			'field' => $this->field,
			'message' => $this->message,
			'expected' => $this->expected,
			'actual' => $this->actual,
		];
	}

	public function toText(): string
	{
		$details = [];

		if ($this->expected !== null || $this->actual !== null) {
			$details[] = 'expected ' . $this->formatValue($this->expected);
			$details[] = 'got ' . $this->formatValue($this->actual);
		}

		$suffix = $details === [] ? '' : ' (' . implode(', ', $details) . ')';

		return $this->record . '.' . $this->field . ': ' . $this->message . $suffix;
	}

	private function formatValue(mixed $value): string
	{
		if (is_string($value)) {
			return '"' . $value . '"';
		}

		if (is_int($value) || is_bool($value)) {
			return var_export($value, true);
		}

		try {
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return get_debug_type($value);
		}

		return $encoded;
	}
}
