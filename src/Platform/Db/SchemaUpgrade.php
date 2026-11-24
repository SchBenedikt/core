<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Db;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function array_map;
use function explode;
use function in_array;
use function preg_match;
use function trim;

/**
 * Small, driver-aware helpers for a host's idempotent schema upgrade.
 *
 * The core still does not run migrations. It only supplies the operations a
 * schema class needs to bring an existing table forward without depending on
 * a non-portable `ADD COLUMN IF NOT EXISTS`: SQLite, MySQL, PostgreSQL and
 * SQL Server expose their catalogues differently, so the few introspection
 * queries live here next to the connection port.
 *
 * Table, column and index names come from schema constants, never from a
 * request, but they are still validated so a future caller cannot turn this
 * helper into an identifier-injection path.
 *
 * Catalogue failures propagate. An unreadable or unsupported catalogue is
 * never interpreted as "table/column/index is missing": no DDL is attempted
 * on a guess, so a failed introspection leaves the schema unchanged.
 */
final class SchemaUpgrade
{
	public static function tableExists(Connection $connection, string $table): bool
	{
		$table = self::identifier($table);
		$parameters = ['table' => $table];
		if ($connection->driver() === 'sqlite') {
			$parameters['type'] = 'table';
		}

		return $connection->fetchValue(
			match ($connection->driver()) {
				'sqlite' => 'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :table',
				'mysql' => 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
					. ' AND TABLE_NAME = :table',
				'pgsql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema()'
					. ' AND table_name = :table',
				'sqlsrv' => 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES'
					. ' WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = :table',
				default => throw new RuntimeException(
					'Idempotent schema upgrades are not implemented for the ' . $connection->driver() . ' driver.',
				),
			},
			$parameters,
		) !== null;
	}

	public static function addColumnIfMissing(
		Connection $connection,
		string $table,
		string $column,
		string $definition,
	): void {
		$table = self::identifier($table);
		$column = self::identifier($column);

		if (in_array($column, self::columns($connection, $table), true)) {
			return;
		}

		try {
			$connection->execute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
		} catch (Throwable $exception) {
			// Two deploy processes can race between the catalogue read and the
			// ALTER. The loser observes the column and treats it as applied;
			// any failure that did not add the column is still rethrown.
			if (!in_array($column, self::columns($connection, $table), true)) {
				throw $exception;
			}
		}
	}

	public static function columnExists(Connection $connection, string $table, string $column): bool
	{
		$table = self::identifier($table);
		$column = self::identifier($column);

		return in_array($column, self::columns($connection, $table), true);
	}

	public static function createIndexIfMissing(
		Connection $connection,
		string $table,
		string $index,
		string $columns,
	): void {
		$table = self::identifier($table);
		$index = self::identifier($index);
		foreach (explode(',', $columns) as $column) {
			self::identifier(trim($column));
		}

		if (self::indexExists($connection, $table, $index)) {
			return;
		}

		try {
			$connection->execute('CREATE INDEX ' . $index . ' ON ' . $table . ' (' . $columns . ')');
		} catch (Throwable $exception) {
			if (!self::indexExists($connection, $table, $index)) {
				throw $exception;
			}
		}
	}

	public static function createTableIfMissing(
		Connection $connection,
		string $table,
		string $statement,
	): void {
		$table = self::identifier($table);

		if (self::tableExists($connection, $table)) {
			return;
		}

		try {
			$connection->execute($statement);
		} catch (Throwable $exception) {
			if (!self::tableExists($connection, $table)) {
				throw $exception;
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private static function columns(Connection $connection, string $table): array
	{
		$rows = match ($connection->driver()) {
			'sqlite' => $connection->fetchAll('PRAGMA table_info(' . $table . ')'),
			'mysql' => $connection->fetchAll(
				'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS'
				. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
				['table' => $table],
			),
			'pgsql' => $connection->fetchAll(
				'SELECT column_name AS name FROM information_schema.columns'
				. ' WHERE table_schema = current_schema() AND table_name = :table',
				['table' => $table],
			),
			'sqlsrv' => $connection->fetchAll(
				'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS'
				. ' WHERE TABLE_SCHEMA = SCHEMA_NAME() AND TABLE_NAME = :table',
				['table' => $table],
			),
			default => throw new RuntimeException(
				'Idempotent schema upgrades are not implemented for the ' . $connection->driver() . ' driver.',
			),
		};

		return array_map(static fn (array $row): string => (string) $row['name'], $rows);
	}

	private static function indexExists(Connection $connection, string $table, string $index): bool
	{
		$parameters = ['table' => $table];
		if ($connection->driver() === 'sqlite') {
			$parameters['type'] = 'index';
		}

		$rows = match ($connection->driver()) {
			'sqlite' => $connection->fetchAll(
				'SELECT name FROM sqlite_master WHERE type = :type AND tbl_name = :table',
				$parameters,
			),
			'mysql' => $connection->fetchAll(
				'SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS'
				. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
				['table' => $table],
			),
			'pgsql' => $connection->fetchAll(
				'SELECT indexname AS name FROM pg_indexes'
				. ' WHERE schemaname = current_schema() AND tablename = :table',
				['table' => $table],
			),
			'sqlsrv' => $connection->fetchAll(
				'SELECT name FROM sys.indexes WHERE object_id = OBJECT_ID(:table)',
				['table' => $table],
			),
			default => throw new RuntimeException(
				'Idempotent schema upgrades are not implemented for the ' . $connection->driver() . ' driver.',
			),
		};

		return in_array(
			$index,
			array_map(static fn (array $row): string => (string) $row['name'], $rows),
			true,
		);
	}

	private static function identifier(string $identifier): string
	{
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
			throw new InvalidArgumentException('Invalid schema identifier: ' . $identifier);
		}

		return $identifier;
	}
}
