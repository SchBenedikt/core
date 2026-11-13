<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FixedInboxResolver;
use AlohaSocial\Core\Tests\Support\RejectingJobQueue;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostTransactionTest extends TestCase
{
	private FrozenClock $clock;

	private PdoConnection $connection;

	private PdoPostRepository $posts;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($this->connection);
		PollSchema::create($this->connection);
		$this->posts = new PdoPostRepository($this->connection);
	}

	public function testASuccessfulPostWritesItsSideRowsAndJobsTogether(): void
	{
		$queue = new InMemoryJobQueue($this->clock, new RandomIdGenerator());
		$service = $this->service($queue);

		$post = $service->create(new CreatePost(
			authorId: 'alice',
			content: 'a transactional #tx post',
			postId: 'tx-success',
		));

		self::assertSame('tx-success', $post->id);
		self::assertNotNull($this->posts->find('tx-success'));
		self::assertSame(1, $this->countRows(PostSchema::HASHTAG_TABLE));
		self::assertSame(1, $queue->pendingCount());
	}

	public function testAFailedQueueWriteRollsBackThePostAndItsHashtag(): void
	{
		$service = $this->service(new RejectingJobQueue());

		try {
			$service->create(new CreatePost(
				authorId: 'alice',
				content: 'must not survive #rollback',
				postId: 'tx-failure',
			));
			self::fail('Expected the delivery queue to fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('The delivery queue is unavailable.', $exception->getMessage());
		}

		self::assertNull($this->posts->find('tx-failure'));
		self::assertSame(0, $this->countRows(PostSchema::TABLE));
		self::assertSame(0, $this->countRows(PostSchema::HASHTAG_TABLE));
	}

	public function testAFailedDeleteKeepsThePostVisible(): void
	{
		$this->service(new InMemoryJobQueue($this->clock, new RandomIdGenerator()))->create(new CreatePost(
			authorId: 'alice',
			content: 'delete me later',
			postId: 'tx-delete',
		));

		try {
			$this->service(new RejectingJobQueue())->delete('alice', 'tx-delete');
			self::fail('Expected the delivery queue to fail.');
		} catch (RuntimeException) {
			// The soft delete and its delivery job must share one transaction.
		}

		self::assertNull($this->posts->find('tx-delete')?->deletedAt);
		self::assertNotNull($this->posts->find('tx-delete'));
	}

	private function service(JobQueue $queue): PostService
	{
		return new PostService(
			posts: $this->posts,
			attachments: new InMemoryAttachmentRepository(),
			queue: $queue,
			inboxes: new FixedInboxResolver(['https://remote.example/inbox']),
			activities: new ActivityFactory(new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			])),
			clock: $this->clock,
			ids: new RandomIdGenerator(),
			transactions: $this->connection,
		);
	}

	private function countRows(string $table): int
	{
		return (int) $this->connection->fetchValue('SELECT COUNT(*) FROM ' . $table);
	}
}
