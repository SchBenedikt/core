<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Db;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

use AlohaSocial\Core\Support\Time;

/**
 * A {@see Connection} on a PDO handle.
 *
 * Errors are exceptions (PDO's error mode is forced), parameters are bound
 * through prepared statements, and booleans and timestamps are normalised so
 * callers never depend on a driver's idea of either.
 */
final class PdoConnection implements Connection
{
	private int $transactionDepth = 0;

	private bool $rollbackOnly = false;

	public function __construct(
		private readonly PDO $pdo,
	) {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	}

	public function execute(string $sql, array $parameters = []): int
	{
		$statement = $this->prepare($sql, $parameters);
		$statement->execute();

		return $statement->rowCount();
	}

	public function fetchAll(string $sql, array $parameters = []): array
	{
		$statement = $this->prepare($sql, $parameters);
		$statement->execute();

		/** @var list<array<string, mixed>> $rows */
		$rows = $statement->fetchAll();

		return $rows;
	}

	public function fetchOne(string $sql, array $parameters = []): ?array
	{
		$statement = $this->prepare($sql, $parameters);
		$statement->execute();
		$row = $statement->fetch();

		if ($row === false) {
			return null;
		}

		/** @var array<string, mixed> $row */
		return $row;
	}

	public function fetchValue(string $sql, array $parameters = []): mixed
	{
		$statement = $this->prepare($sql, $parameters);
		$statement->execute();
		$value = $statement->fetchColumn();

		return $value === false ? null : $value;
	}

	public function transaction(callable $work): mixed
	{
		if ($this->transactionDepth === 0) {
			$this->pdo->beginTransaction();
			$this->rollbackOnly = false;
		}

		$this->transactionDepth++;

		try {
			$result = $work($this);
		} catch (Throwable $e) {
			// A failure anywhere in the chain marks the whole chain, even if
			// an outer caller catches the exception and carries on: committing
			// half of a transaction is never what the caller meant.
			$this->rollbackOnly = true;
			$this->transactionDepth--;

			if ($this->transactionDepth === 0) {
				$this->rollBack();
			}

			throw $e;
		}

		$this->transactionDepth--;

		if ($this->transactionDepth === 0) {
			if ($this->rollbackOnly) {
				$this->rollBack();

				throw new DatabaseException(
					'An inner transaction failed; the whole transaction was rolled back.',
				);
			}

			if ($this->pdo->inTransaction()) {
				$this->pdo->commit();
			}
		}

		return $result;
	}

	private function rollBack(): void
	{
		if ($this->pdo->inTransaction()) {
			$this->pdo->rollBack();
		}

		$this->rollbackOnly = false;
	}

	public function driver(): string
	{
		$name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

		return is_string($name) ? $name : 'unknown';
	}

	/**
	 * @param array<string|int, scalar|null> $parameters
	 */
	private function prepare(string $sql, array $parameters): PDOStatement
	{
		if (trim($sql) === '') {
			throw new InvalidArgumentException('Refusing to run an empty statement.');
		}

		$statement = $this->pdo->prepare($sql);
		if (!$statement instanceof PDOStatement) {
			throw new DatabaseException('The driver did not prepare the statement.');
		}

		$position = 1;
		foreach ($parameters as $key => $value) {
			$name = is_int($key)
				? $position++
				: (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);

			$statement->bindValue(
				$name,
				$this->normalize($value),
				$this->parameterType($value),
			);
		}

		return $statement;
	}

	private function normalize(mixed $value): mixed
	{
		if ($value instanceof DateTimeImmutable) {
			return Time::format($value);
		}

		if (is_bool($value)) {
			return $value ? 1 : 0;
		}

		if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
			return $value;
		}

		throw new InvalidArgumentException(
			'Unsupported parameter type: ' . get_debug_type($value),
		);
	}

	private function parameterType(mixed $value): int
	{
		return match (true) {
			$value === null => PDO::PARAM_NULL,
			is_int($value), is_bool($value) => PDO::PARAM_INT,
			default => PDO::PARAM_STR,
		};
	}
}
