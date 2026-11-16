<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform;

use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;
use AlohaSocial\Core\Tests\Support\CatalogConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The catalogue SQL differs by driver. These tests run the real branches with
 * a stateful connection double, rather than treating SQLite as proof for
 * MySQL, PostgreSQL and SQL Server.
 */
final class SchemaUpgradeTest extends TestCase
{
	#[DataProvider('drivers')]
	public function testEveryDriverCatalogueBranchIsUsed(
		string $driver,
		string $tableQuery,
		string $columnQuery,
		string $indexQuery,
	): void {
		$connection = new CatalogConnection($driver);

		self::assertFalse(SchemaUpgrade::tableExists($connection, JobSchema::TABLE));
		self::assertTrue($connection->sawCatalogExact($tableQuery));

		$connection->tables[JobSchema::TABLE] = true;
		self::assertTrue(SchemaUpgrade::tableExists($connection, JobSchema::TABLE));

		$connection->columns[JobSchema::TABLE] = [];
		SchemaUpgrade::addColumnIfMissing(
			$connection,
			JobSchema::TABLE,
			'subject_type',
			'VARCHAR(32) NULL',
		);

		self::assertCount(1, $connection->statements);
		self::assertStringContainsString('ALTER TABLE ' . JobSchema::TABLE, $connection->statements[0]);
		self::assertTrue($connection->sawCatalogExact($columnQuery));

		$connection->columns[JobSchema::TABLE] = ['subject_type'];
		SchemaUpgrade::addColumnIfMissing(
			$connection,
			JobSchema::TABLE,
			'subject_type',
			'VARCHAR(32) NULL',
		);
		self::assertCount(1, $connection->statements);

		$connection->indexes[JobSchema::TABLE] = [];
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			JobSchema::TABLE,
			JobSchema::SUBJECT_INDEX,
			'subject_type, subject_id',
		);
		self::assertCount(2, $connection->statements);
		self::assertStringContainsString('CREATE INDEX ' . JobSchema::SUBJECT_INDEX, $connection->statements[1]);
		self::assertTrue($connection->sawCatalogExact($indexQuery));

		$connection->indexes[JobSchema::TABLE] = [JobSchema::SUBJECT_INDEX];
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			JobSchema::TABLE,
			JobSchema::SUBJECT_INDEX,
			'subject_type, subject_id',
		);
		self::assertCount(2, $connection->statements);

		$connection->tables = [];
		SchemaUpgrade::createTableIfMissing(
			$connection,
			JobSchema::TABLE,
			'CREATE TABLE ' . JobSchema::TABLE . ' (id INTEGER)',
		);
		self::assertCount(3, $connection->statements);
		self::assertStringContainsString('CREATE TABLE ' . JobSchema::TABLE, $connection->statements[2]);

		$connection->tables[JobSchema::TABLE] = true;
		SchemaUpgrade::createTableIfMissing(
			$connection,
			JobSchema::TABLE,
			'CREATE TABLE ' . JobSchema::TABLE . ' (id INTEGER)',
		);
		self::assertCount(3, $connection->statements);
	}

	#[DataProvider('drivers')]
	public function testACatalogueFailureIsNeverTreatedAsMissing(
		string $driver,
		string $tableQuery,
		string $columnQuery,
		string $indexQuery,
	): void {
		$connection = new CatalogConnection($driver);
		$connection->failure = new RuntimeException('catalogue unavailable');

		$calls = [
			static fn (): bool => SchemaUpgrade::tableExists($connection, JobSchema::TABLE),
			static fn (): mixed => SchemaUpgrade::addColumnIfMissing(
				$connection,
				JobSchema::TABLE,
				'subject_type',
				'VARCHAR(32) NULL',
			),
			static fn (): mixed => SchemaUpgrade::createIndexIfMissing(
				$connection,
				JobSchema::TABLE,
				JobSchema::SUBJECT_INDEX,
				'subject_type, subject_id',
			),
			static fn (): mixed => SchemaUpgrade::createTableIfMissing(
				$connection,
				JobSchema::TABLE,
				'CREATE TABLE ' . JobSchema::TABLE . ' (id INTEGER)',
			),
		];

		foreach ($calls as $call) {
			try {
				$call();
				self::fail('A failed catalogue read must not be guessed as absent.');
			} catch (RuntimeException $exception) {
				self::assertSame('catalogue unavailable', $exception->getMessage());
			}
		}

		self::assertSame([], $connection->statements);
	}

	public function testAnUnsupportedDriverFailsClosedInsteadOfGuessing(): void
	{
		$connection = new CatalogConnection('oracle');

		try {
			SchemaUpgrade::tableExists($connection, JobSchema::TABLE);
			self::fail('An unsupported driver must not answer the catalogue.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('not implemented', $exception->getMessage());
		}

		self::assertSame([], $connection->statements);
	}

	/**
	 * @return iterable<string, array{string, string, string, string}>
	 */
	public static function drivers(): iterable
	{
		yield 'sqlite' => [
			'sqlite',
			'sqlite_master',
			'PRAGMA table_info',
			'tbl_name = :table',
		];
		yield 'mysql' => [
			'mysql',
			'information_schema.TABLES',
			'information_schema.COLUMNS',
			'information_schema.STATISTICS',
		];
		yield 'pgsql' => [
			'pgsql',
			'information_schema.tables',
			'information_schema.columns',
			'pg_indexes',
		];
		yield 'sqlsrv' => [
			'sqlsrv',
			'INFORMATION_SCHEMA.TABLES',
			'INFORMATION_SCHEMA.COLUMNS',
			'sys.indexes',
		];
	}
}
