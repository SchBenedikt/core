<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Db;

/**
 * The database port.
 *
 * Deliberately smaller than a query builder: the core's request classes build
 * SQL themselves and hand it over with bound parameters. That keeps the port
 * cheap to implement on both hosts and keeps every statement explicit.
 */
interface Connection
{
	/**
	 * Runs a statement and returns the number of affected rows.
	 *
	 * @param array<string|int, scalar|null> $parameters
	 *
	 * @throws DatabaseException
	 */
	public function execute(string $sql, array $parameters = []): int;

	/**
	 * @param array<string|int, scalar|null> $parameters
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws DatabaseException
	 */
	public function fetchAll(string $sql, array $parameters = []): array;

	/**
	 * @param array<string|int, scalar|null> $parameters
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws DatabaseException
	 */
	public function fetchOne(string $sql, array $parameters = []): ?array;

	/**
	 * The first column of the first row, or null when nothing matched.
	 *
	 * @param array<string|int, scalar|null> $parameters
	 *
	 * @throws DatabaseException
	 */
	public function fetchValue(string $sql, array $parameters = []): mixed;

	/**
	 * Runs `$work` in a transaction and returns its result.
	 *
	 * Nested calls join the outer transaction; an exception that leaves any
	 * level rolls the whole chain back.
	 *
	 * @template T
	 *
	 * @param callable(self): T $work
	 *
	 * @return T
	 *
	 * @throws DatabaseException
	 */
	public function transaction(callable $work): mixed;

	/**
	 * The PDO driver name, for the few places where SQL must differ.
	 */
	public function driver(): string;
}
