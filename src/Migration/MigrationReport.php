<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use JsonException;

/**
 * The immutable result of a cutover validation.
 *
 * `exitCode()` is intentionally part of the report: a CLI must not decide
 * differently from a caller that used the class directly.
 */
final readonly class MigrationReport
{
	/**
	 * @param list<MigrationViolation> $violations
	 */
	public function __construct(
		public string $source,
		public array $violations,
	) {
	}

	public function passed(): bool
	{
		return $this->violations === [];
	}

	public function exitCode(): int
	{
		return $this->passed() ? 0 : 1;
	}

	/**
	 * @return list<MigrationViolation>
	 */
	public function violations(): array
	{
		return $this->violations;
	}

	/**
	 * @return array{source: string, passed: bool, exit_code: int, violations: list<array{record: string, field: string, message: string, expected: mixed, actual: mixed}>}
	 */
	public function toArray(): array
	{
		return [
			'source' => $this->source,
			'passed' => $this->passed(),
			'exit_code' => $this->exitCode(),
			'violations' => array_map(
				static fn (MigrationViolation $violation): array => $violation->toArray(),
				$this->violations,
			),
		];
	}

	/**
	 * @throws JsonException if a violation contains a value that cannot be encoded
	 */
	public function toJson(): string
	{
		return json_encode(
			$this->toArray(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);
	}

	public function toText(): string
	{
		$lines = [
			'Migration cutover: ' . $this->source,
			'Result: ' . ($this->passed() ? 'PASS' : 'FAIL (' . count($this->violations) . ' violation(s))'),
		];

		foreach ($this->violations as $violation) {
			$lines[] = '  - ' . $violation->toText();
		}

		return implode(PHP_EOL, $lines) . PHP_EOL;
	}
}
