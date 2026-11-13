<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PdoPollVoteRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;

final class PdoPollVoteRepositoryTest extends PollVoteRepositoryTestCase
{
	private PdoPostRepository $posts;

	private PollVoteRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($connection);
		PollSchema::create($connection);
		$this->posts = new PdoPostRepository($connection);
		$this->repository = new PdoPollVoteRepository($connection);
	}

	protected function repository(): PollVoteRepository
	{
		return $this->repository;
	}

	protected function seedPoll(string $postId = 'p1', bool $multiple = false): void
	{
		$this->posts->insert($this->post($postId, $multiple));
	}

	protected function storedPoll(string $postId = 'p1'): ?Poll
	{
		return $this->posts->find($postId)?->poll;
	}

	private function post(string $postId, bool $multiple): Post
	{
		return new Post(
			id: $postId,
			authorId: 'alice',
			content: 'A poll',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			poll: new Poll(
				options: [new PollOption('A'), new PollOption('B'), new PollOption('C')],
				multiple: $multiple,
				hideTotals: false,
				expiresAt: Time::parse('2026-11-11T11:00:00Z'),
			),
		);
	}
}
