<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Account\PdoAccountDataRepository;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Account\AccountDataService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function ksort;

/**
 * The upgrade path is exercised against the schema an older installation
 * actually has: no deletion table and a `social_job` without subject columns.
 */
final class AccountDeletionSchemaUpgradeTest extends TestCase
{
	private Connection $database;

	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->database = new PdoConnection(new PDO('sqlite::memory:'));
		$this->clock = new FrozenClock(Time::parse('2026-11-16T12:00:00Z'));
	}

	public function testAnOlderQueueAndMissingDeletionTableUpgradeIdempotently(): void
	{
		$this->createLegacyJobTable();
		$this->database->execute(
			'INSERT INTO ' . JobSchema::TABLE
			. ' (id, type, payload, available_at, attempts, reserved_at, dead_at)'
			. " VALUES ('legacy-job', 'atproto.delete', '{\"user_id\":\"bob\"}',"
			. " '2026-11-16T11:00:00Z', 0, NULL, NULL)",
		);

		JobSchema::upgrade($this->database);
		JobSchema::upgrade($this->database);
		AccountDeletionSchema::upgrade($this->database);
		AccountDeletionSchema::upgrade($this->database);

		self::assertContains(
			'subject_type',
			array_column($this->database->fetchAll('PRAGMA table_info(' . JobSchema::TABLE . ')'), 'name'),
		);
		self::assertContains(
			'subject_id',
			array_column($this->database->fetchAll('PRAGMA table_info(' . JobSchema::TABLE . ')'), 'name'),
		);
		self::assertContains(
			JobSchema::SUBJECT_INDEX,
			array_column($this->database->fetchAll('PRAGMA index_list(' . JobSchema::TABLE . ')'), 'name'),
		);
		self::assertContains(
			AccountDeletionSchema::STATE_INDEX,
			array_column(
				$this->database->fetchAll('PRAGMA index_list(' . AccountDeletionSchema::TABLE . ')'),
				'name',
			),
		);

		$queue = new PdoJobQueue($this->database, $this->clock, new SequenceIdGenerator('job-'));
		$legacy = $queue->reserve(10, $this->clock->now());
		self::assertCount(1, $legacy);
		self::assertNull($legacy[0]->subject);
		self::assertSame(['user_id' => 'bob'], $legacy[0]->payload);
		$queue->complete($legacy[0]);

		PostSchema::create($this->database);
		$this->database->execute(
			'INSERT INTO ' . PostSchema::TABLE . ' (id, author_id, content, visibility, published_at)'
			. " VALUES ('alice-post', 'alice', 'remove me', 'public', '2026-11-16T11:00:00Z')",
		);
		$this->database->execute(
			'INSERT INTO ' . PostSchema::TABLE . ' (id, author_id, content, visibility, published_at)'
			. " VALUES ('bob-post', 'bob', 'keep me', 'public', '2026-11-16T11:00:00Z')",
		);

		$queue->push('atproto.delete', ['user_id' => 'alice'], null, JobSubject::account('alice'));
		$queue->push('atproto.delete', ['user_id' => 'bob'], null, JobSubject::account('bob'));

		$repository = new PdoAccountDataRepository($this->database);
		$service = new AccountDataService(
			$repository,
			new InMemoryBlobStore(),
			new ActivityFactory(new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			])),
			$queue,
			$this->clock,
		);

		$first = $service->erase('alice');

		self::assertSame('erased', $first->state);
		self::assertSame(1, $first->counts[PostSchema::TABLE]);
		self::assertSame(1, $queue->pendingCount());
		self::assertSame(410, $repository->accountWriteStatus('alice'));
		self::assertSame(
			['bob-post'],
			array_column($this->database->fetchAll('SELECT id FROM ' . PostSchema::TABLE . ' ORDER BY id'), 'id'),
		);

		$second = $service->erase('alice');

		self::assertSame($first->toArray(), $second->toArray());
		self::assertSame('erased', $service->deletionStatus('alice')->state);
	}

	public function testUpgradeCreatesBothSchemasOnAFreshInstallation(): void
	{
		JobSchema::upgrade($this->database);
		AccountDeletionSchema::upgrade($this->database);
		JobSchema::upgrade($this->database);
		AccountDeletionSchema::upgrade($this->database);

		self::assertNotNull($this->database->fetchValue(
			'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :table',
			['type' => 'table', 'table' => JobSchema::TABLE],
		));
		self::assertNotNull($this->database->fetchValue(
			'SELECT 1 FROM sqlite_master WHERE type = :type AND name = :table',
			['type' => 'table', 'table' => AccountDeletionSchema::TABLE],
		));
	}

	public function testUpgradeCreatesExactlyTheSameEmptySchemaAsCreate(): void
	{
		$created = $this->newDatabase();
		JobSchema::create($created);
		AccountDeletionSchema::create($created);

		$upgraded = $this->newDatabase();
		JobSchema::upgrade($upgraded);
		AccountDeletionSchema::upgrade($upgraded);

		foreach ([JobSchema::TABLE, AccountDeletionSchema::TABLE] as $table) {
			self::assertSame(
				$this->schema($created, $table),
				$this->schema($upgraded, $table),
				'The upgrade schema differs from create() for ' . $table . '.',
			);
		}
	}

	public function testLegacyQueueUpgradeMatchesTheFreshQueueSchema(): void
	{
		$created = $this->newDatabase();
		JobSchema::create($created);

		$legacy = $this->newDatabase();
		$this->createLegacyJobTable($legacy);
		JobSchema::upgrade($legacy);

		self::assertSame(
			$this->schema($created, JobSchema::TABLE),
			$this->schema($legacy, JobSchema::TABLE),
		);
	}

	/**
	 * @return array{
	 *     columns: array<string, array{type: string, not_null: int, default: mixed, primary_key: int}>,
	 *     indexes: array<string, array{unique: int, columns: list<string>}>,
	 * }
	 */
	private function schema(Connection $database, string $table): array
	{
		$columns = [];
		foreach ($database->fetchAll('PRAGMA table_info(' . $table . ')') as $row) {
			$columns[(string) $row['name']] = [
				'type' => (string) $row['type'],
				'not_null' => (int) $row['notnull'],
				'default' => $row['dflt_value'],
				'primary_key' => (int) $row['pk'],
			];
		}
		ksort($columns);

		$indexes = [];
		foreach ($database->fetchAll('PRAGMA index_list(' . $table . ')') as $row) {
			$name = (string) $row['name'];
			$indexes[$name] = [
				'unique' => (int) $row['unique'],
				'columns' => array_map(
					static fn (array $info): string => (string) $info['name'],
					$database->fetchAll('PRAGMA index_info(' . $name . ')'),
				),
			];
		}
		ksort($indexes);

		return ['columns' => $columns, 'indexes' => $indexes];
	}

	private function newDatabase(): Connection
	{
		return new PdoConnection(new PDO('sqlite::memory:'));
	}

	private function createLegacyJobTable(?Connection $database = null): void
	{
		$database ??= $this->database;
		$database->execute(
			'CREATE TABLE ' . JobSchema::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' type VARCHAR(64) NOT NULL,'
			. ' payload TEXT NOT NULL,'
			. ' available_at VARCHAR(20) NOT NULL,'
			. ' attempts INTEGER NOT NULL,'
			. ' reserved_at VARCHAR(20) NULL,'
			. ' dead_at VARCHAR(20) NULL'
			. ')',
		);
	}
}
