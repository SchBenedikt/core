<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoPublicationRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAtprotoPublicationRepositoryTest extends AtprotoPublicationRepositoryTestCase
{
	private AtprotoPublicationRepository $repository;
	private PdoConnection $connection;

	protected function setUp(): void
	{
		$this->connection = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoPublicationSchema::create($this->connection);

		$this->repository = new PdoAtprotoPublicationRepository($this->connection);
	}

	protected function repository(): AtprotoPublicationRepository
	{
		return $this->repository;
	}

	public function testUpgradeAddsCidToAnExistingPublicationTable(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		$connection->execute('CREATE TABLE ' . AtprotoPublicationSchema::TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL, post_id VARCHAR(64) NOT NULL,'
			. ' rkey VARCHAR(13) NOT NULL, uri VARCHAR(512) NOT NULL,'
			. ' pds_url VARCHAR(512) NULL, published_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, post_id))');
		$connection->execute(
			'INSERT INTO ' . AtprotoPublicationSchema::TABLE
			. ' (local_user_id, post_id, rkey, uri, pds_url, published_at)'
			. ' VALUES (:user, :post, :rkey, :uri, :pds, :at)',
			[
				'user' => 'alice', 'post' => 'post-1', 'rkey' => '234567abcdefg',
				'uri' => 'at://did:plc:alice/app.bsky.feed.post/234567abcdefg',
				'pds' => 'https://pds.example', 'at' => '2026-11-11T10:00:00Z',
			],
		);

		AtprotoPublicationSchema::upgrade($connection);

		$columns = $connection->fetchAll('PRAGMA table_info(' . AtprotoPublicationSchema::TABLE . ')');
		self::assertContains('cid', array_column($columns, 'name'));
		self::assertContains('account_did', array_column($columns, 'name'));
		self::assertSame('did:plc:alice', (string) $connection->fetchValue(
			'SELECT account_did FROM ' . AtprotoPublicationSchema::TABLE . ' WHERE post_id = :post',
			['post' => 'post-1'],
		));
		AtprotoPublicationSchema::upgrade($connection);
		self::assertSame(1, count(array_filter($connection->fetchAll(
			'PRAGMA table_info(' . AtprotoPublicationSchema::TABLE . ')',
		), static fn (array $column): bool => $column['name'] === 'cid')));
	}
}
