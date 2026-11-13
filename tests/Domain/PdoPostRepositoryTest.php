<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;

final class PdoPostRepositoryTest extends PostRepositoryTestCase
{
	private PdoConnection $connection;

	private PostRepository $repository;

	protected function setUp(): void
	{
		$this->connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($this->connection);
		PollSchema::create($this->connection);

		$this->repository = new PdoPostRepository($this->connection);
	}

	public function testAFailedAttachmentLinkRollsBackThePost(): void
	{
		$this->connection->execute(
			'INSERT INTO ' . PostSchema::ATTACHMENT_TABLE
			. ' (post_id, attachment_id, position) VALUES (:post_id, :attachment_id, :position)',
			['post_id' => 'rollback', 'attachment_id' => 'a1', 'position' => 0],
		);

		try {
			$this->repository->insert($this->post(
				'rollback',
				'alice',
				Visibility::Public,
				'2026-11-11T10:00:00Z',
				attachmentIds: ['a1'],
				content: '#tx rollback',
			));
			self::fail('Expected the duplicate attachment link to fail.');
		} catch (DatabaseException) {
			// The forced failure must leave nothing behind.
		}

		self::assertNull($this->repository->find('rollback'));
		self::assertSame(0, $this->countRows(PostSchema::TABLE));
		self::assertSame(0, $this->countRows(PostSchema::HASHTAG_TABLE));
	}

	public function testAFailedHashtagRollsBackThePost(): void
	{
		$this->connection->execute(
			'INSERT INTO ' . PostSchema::HASHTAG_TABLE
			. ' (post_id, hashtag, position) VALUES (:post_id, :hashtag, :position)',
			['post_id' => 'rollback', 'hashtag' => 'tx', 'position' => 0],
		);

		try {
			$this->repository->insert($this->post(
				'rollback',
				'alice',
				Visibility::Public,
				'2026-11-11T10:00:00Z',
				content: '#tx rollback',
			));
			self::fail('Expected the duplicate hashtag to fail.');
		} catch (DatabaseException) {
			// The forced failure must leave nothing behind.
		}

		self::assertNull($this->repository->find('rollback'));
		self::assertSame(0, $this->countRows(PostSchema::TABLE));
		self::assertSame(1, $this->countRows(PostSchema::HASHTAG_TABLE));
	}

	public function testAFailedPollOptionRollsBackThePostAndPoll(): void
	{
		$this->connection->execute(
			'INSERT INTO ' . PollSchema::OPTION_TABLE
			. ' (post_id, position, title, votes_count) VALUES (:post_id, :position, :title, 0)',
			['post_id' => 'rollback', 'position' => 0, 'title' => 'Existing'],
		);

		try {
			$this->repository->insert($this->post(
				'rollback',
				'alice',
				Visibility::Public,
				'2026-11-11T10:00:00Z',
				poll: new Poll(
					options: [new PollOption('Yes'), new PollOption('No')],
					multiple: false,
					hideTotals: false,
					expiresAt: Time::parse('2026-11-11T11:00:00Z'),
				),
			));
			self::fail('Expected the duplicate poll option to fail.');
		} catch (DatabaseException) {
			// The forced failure must leave nothing behind.
		}

		self::assertNull($this->repository->find('rollback'));
		self::assertSame(0, $this->countRows(PostSchema::TABLE));
		self::assertSame(0, $this->countRows(PollSchema::TABLE));
		self::assertSame(1, $this->countRows(PollSchema::OPTION_TABLE));
	}

	private function countRows(string $table): int
	{
		return (int) $this->connection->fetchValue('SELECT COUNT(*) FROM ' . $table);
	}

	protected function repository(): PostRepository
	{
		return $this->repository;
	}

	protected function searchRepository(): PostSearchRepository
	{
		return $this->repository;
	}
}
