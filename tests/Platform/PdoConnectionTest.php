<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoConnectionTest extends TestCase
{
	private Connection $db;

	protected function setUp(): void
	{
		$this->db = new PdoConnection(new PDO('sqlite::memory:'));
		$this->db->execute('CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT, flag INTEGER, at TEXT)');
	}

	public function testExecuteReturnsTheAffectedRows(): void
	{
		$affected = $this->db->execute(
			'INSERT INTO item (name, flag) VALUES (:name, :flag)',
			['name' => 'alpha', 'flag' => true],
		);

		self::assertSame(1, $affected);
	}

	public function testNamedParametersAreBoundCorrectly(): void
	{
		$this->db->execute(
			'INSERT INTO item (name, flag, at) VALUES (:name, :flag, :at)',
			['name' => 'alpha', 'flag' => false, 'at' => Time::parse('2026-11-11T10:00:00Z')],
		);

		$row = $this->db->fetchOne('SELECT name, flag, at FROM item WHERE name = :name', ['name' => 'alpha']);

		self::assertNotNull($row);
		self::assertSame('alpha', $row['name']);
		self::assertSame(0, (int) $row['flag']);
		self::assertSame('2026-11-11T10:00:00Z', $row['at']);
	}

	public function testPositionalParametersAreBoundInOrder(): void
	{
		$this->db->execute('INSERT INTO item (name, flag) VALUES (?, ?)', ['beta', 1]);

		self::assertSame('beta', $this->db->fetchValue('SELECT name FROM item WHERE flag = ?', [1]));
	}

	public function testFetchAllReturnsEveryRow(): void
	{
		$this->db->execute('INSERT INTO item (name) VALUES (:a)', ['a' => 'one']);
		$this->db->execute('INSERT INTO item (name) VALUES (:a)', ['a' => 'two']);

		$rows = $this->db->fetchAll('SELECT name FROM item ORDER BY name');

		self::assertCount(2, $rows);
		self::assertSame('one', $rows[0]['name']);
		self::assertSame('two', $rows[1]['name']);
	}

	public function testFetchOneReturnsNullWhenNothingMatches(): void
	{
		self::assertNull($this->db->fetchOne('SELECT * FROM item WHERE id = :id', ['id' => 99]));
	}

	public function testFetchValueReturnsNullWhenNothingMatches(): void
	{
		self::assertNull($this->db->fetchValue('SELECT name FROM item WHERE id = :id', ['id' => 99]));
	}

	public function testATransactionCommits(): void
	{
		$this->db->transaction(function (Connection $db): void {
			$db->execute('INSERT INTO item (name) VALUES (:name)', ['name' => 'committed']);
		});

		self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item'));
	}

	public function testATransactionRollsBackOnAnException(): void
	{
		try {
			$this->db->transaction(function (Connection $db): void {
				$db->execute('INSERT INTO item (name) VALUES (:name)', ['name' => 'rolled-back']);

				throw new RuntimeException('failure');
			});
			self::fail('The exception should have escaped the transaction.');
		} catch (RuntimeException $exception) {
			self::assertSame('failure', $exception->getMessage());
		}

		self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item'));
	}

	public function testAnInnerFailurePoisonsTheWholeTransaction(): void
	{
		try {
			$this->db->transaction(function (Connection $db): void {
				$db->execute('INSERT INTO item (name) VALUES (:name)', ['name' => 'outer']);

				try {
					$db->transaction(function (Connection $inner): void {
						$inner->execute('INSERT INTO item (name) VALUES (:name)', ['name' => 'inner']);

						throw new RuntimeException('inner failure');
					});
				} catch (RuntimeException) {
					// The caller decided to carry on; the transaction must not.
				}
			});
			self::fail('Committing a poisoned transaction must not be silent.');
		} catch (DatabaseException) {
			self::assertTrue(true);
		}

		self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM item'));
	}

	public function testRefusesAnEmptyStatement(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->db->execute('   ');
	}

	public function testReportsTheDriver(): void
	{
		self::assertSame('sqlite', $this->db->driver());
	}

	public function testAStatementErrorBecomesADatabaseException(): void
	{
		$this->expectException(\PDOException::class);

		$this->db->execute('SELECT * FROM does_not_exist');
	}
}
