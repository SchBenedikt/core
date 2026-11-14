<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use JsonException;

use function array_filter;
use function array_map;
use function array_values;

/**
 * The immutable result of a cutover validation.
 *
 * The three states are explicit so a caller cannot confuse "nothing to do"
 * with "checked and clean": `pass` has no findings, `warn` has only warnings,
 * `fail` has at least one failure that must be repaired before the cutover.
 * Warnings keep the exit code at 0 because they do not invalidate identity;
 * they exist so an operator sees a semantic difference the export cannot
 * express (a dead job, unverifiable media bytes) instead of losing it in a
 * silent "everything is fine".
 *
 * `exitCode()` is intentionally part of the report: a CLI must not decide
 * differently from a caller that used the class directly.
 */
final readonly class MigrationReport
{
	public const STATUS_PASS = 'pass';

	public const STATUS_WARN = 'warn';

	public const STATUS_FAIL = 'fail';

	/**
	 * @param list<MigrationViolation> $violations
	 */
	public function __construct(
		public string $source,
		public array $violations,
	) {
	}

	/**
	 * Whether the dataset may be cut over. Warnings do not block.
	 */
	public function passed(): bool
	{
		return $this->failures() === [];
	}

	/**
	 * @return self::STATUS_*
	 */
	public function status(): string
	{
		if ($this->failures() !== []) {
			return self::STATUS_FAIL;
		}

		return $this->warnings() === [] ? self::STATUS_PASS : self::STATUS_WARN;
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
	 * @return list<MigrationViolation>
	 */
	public function failures(): array
	{
		return array_values(array_filter(
			$this->violations,
			static fn (MigrationViolation $violation): bool => $violation->isFailure(),
		));
	}

	/**
	 * @return list<MigrationViolation>
	 */
	public function warnings(): array
	{
		return array_values(array_filter(
			$this->violations,
			static fn (MigrationViolation $violation): bool => !$violation->isFailure(),
		));
	}

	/**
	 * @return array{
	 *     source: string,
	 *     status: string,
	 *     passed: bool,
	 *     exit_code: int,
	 *     failures: int,
	 *     warnings: int,
	 *     violations: list<array{record: string, field: string, message: string, expected: mixed, actual: mixed, severity: string}>
	 * }
	 */
	public function toArray(): array
	{
		return [
			'source' => $this->source,
			'status' => $this->status(),
			'passed' => $this->passed(),
			'exit_code' => $this->exitCode(),
			'failures' => count($this->failures()),
			'warnings' => count($this->warnings()),
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
			'Result: ' . strtoupper($this->status())
				. ' (' . count($this->failures()) . ' failure(s), '
				. count($this->warnings()) . ' warning(s))',
		];

		foreach ($this->violations as $violation) {
			$lines[] = '  - ' . $violation->toText();
		}

		return implode(PHP_EOL, $lines) . PHP_EOL;
	}
}
