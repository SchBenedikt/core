<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Platform\Db\Connection;
use LogicException;
use Throwable;

use function array_map;
use function preg_match;
use function str_contains;
use function strtolower;

/**
 * A deterministic Connection double for the schema-catalogue branches.
 *
 * It does not execute DDL. Tests change `$tables`, `$columns` and `$indexes`
 * explicitly, which keeps each catalogue state independent from the
 * implementation under test. Every catalogue query is recorded so a test can
 * prove that the vendor-specific SQL was actually used.
 */
final class CatalogConnection implements Connection
{
	/** @var array<string, true> */
	public array $tables = [];

	/** @var array<string, list<string>> */
	public array $columns = [];

	/** @var array<string, list<string>> */
	public array $indexes = [];

	/** @var list<string> */
	public array $statements = [];

	/** @var list<string> */
	public array $catalogQueries = [];

	public ?Throwable $failure = null;

	public function __construct(
		private readonly string $driverName,
	) {
	}

	public function driver(): string
	{
		return $this->driverName;
	}

	public function execute(string $sql, array $parameters = []): int
	{
		$this->statements[] = $sql;

		return 0;
	}

	public function fetchAll(string $sql, array $parameters = []): array
	{
		$this->recordCatalogQuery($sql);
		$table = $this->tableName($sql, $parameters);
		$normalized = strtolower($sql);

		if (str_contains($normalized, 'pragma table_info')) {
			return $this->names($this->columns[$table] ?? []);
		}

		if (str_contains($normalized, 'information_schema.columns')) {
			return $this->names($this->columns[$table] ?? []);
		}

		if (str_contains($normalized, 'information_schema.statistics')
			|| str_contains($normalized, 'pg_indexes')
			|| str_contains($normalized, 'sys.indexes')
		) {
			return $this->names($this->indexes[$table] ?? []);
		}

		if (str_contains($normalized, 'sqlite_master')) {
			return $this->names($this->indexes[$table] ?? []);
		}

		throw new LogicException('Unexpected catalogue query: ' . $sql);
	}

	public function fetchOne(string $sql, array $parameters = []): ?array
	{
		throw new LogicException('A schema upgrade must not read one catalogue row.');
	}

	public function fetchValue(string $sql, array $parameters = []): mixed
	{
		$this->recordCatalogQuery($sql);
		$table = (string) ($parameters['table'] ?? '');
		$normalized = strtolower($sql);

		if (str_contains($normalized, 'sqlite_master')
			|| str_contains($normalized, 'information_schema.tables')
			|| str_contains($normalized, 'sys.tables')
		) {
			return isset($this->tables[$table]) ? 1 : null;
		}

		throw new LogicException('Unexpected catalogue query: ' . $sql);
	}

	public function transaction(callable $work): mixed
	{
		return $work($this);
	}

	public function sawCatalogExact(string $fragment): bool
	{
		foreach ($this->catalogQueries as $query) {
			if (str_contains($query, $fragment)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $names
	 * @return list<array{name: string}>
	 */
	private function names(array $names): array
	{
		return array_map(static fn (string $name): array => ['name' => $name], $names);
	}

	/**
	 * SQLite's PRAGMA has no bind parameter, so the production code embeds
	 * the already-validated table name in the statement.
	 *
	 * @param array<string|int, scalar|null> $parameters
	 */
	private function tableName(string $sql, array $parameters): string
	{
		if (isset($parameters['table'])) {
			return (string) $parameters['table'];
		}

		if (preg_match('/pragma\s+table_info\(([A-Za-z_][A-Za-z0-9_]*)\)/i', $sql, $matches) === 1) {
			return $matches[1];
		}

		return '';
	}

	private function recordCatalogQuery(string $sql): void
	{
		$this->catalogQueries[] = $sql;

		if ($this->failure !== null) {
			throw $this->failure;
		}
	}
}
