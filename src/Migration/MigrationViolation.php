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
 * One finding from a cutover validation.
 *
 * A `fail` means the exported data would lose or change identity and the
 * cutover must not proceed. A `warn` is a semantic difference the operator has
 * to know about but that cannot be resolved by the export: a dead-lettered job
 * that will not be retried, or media without a checksum whose bytes therefore
 * cannot be verified. Warnings never block, but they keep a "looks fine"
 * report from being mistaken for "everything is provably fine".
 *
 * The record is deliberately a path (`following[2]`) rather than a database
 * row id: the export is the only input and the host needs to be able to find
 * the same entry without the validator knowing the host's schema.
 */
final readonly class MigrationViolation
{
	public const FAIL = 'fail';

	public const WARN = 'warn';

	public function __construct(
		public string $record,
		public string $field,
		public string $message,
		public mixed $expected = null,
		public mixed $actual = null,
		public string $severity = self::FAIL,
	) {
	}

	public function isFailure(): bool
	{
		return $this->severity === self::FAIL;
	}

	/**
	 * @return array{record: string, field: string, message: string, expected: mixed, actual: mixed, severity: string}
	 */
	public function toArray(): array
	{
		return [
			'record' => $this->record,
			'field' => $this->field,
			'message' => $this->message,
			'expected' => $this->expected,
			'actual' => $this->actual,
			'severity' => $this->severity,
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
		$prefix = $this->isFailure() ? 'FAIL ' : 'WARN ';

		return $prefix . $this->record . '.' . $this->field . ': ' . $this->message . $suffix;
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
