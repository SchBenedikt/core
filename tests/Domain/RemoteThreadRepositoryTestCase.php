<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both remote thread-cache repositories must satisfy.
 */
abstract class RemoteThreadRepositoryTestCase extends TestCase
{
	abstract protected function repository(): RemoteThreadRepository;

	public function testUpsertAndFind(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->post('https://remote.example/notes/1', 'alice'));

		$found = $repository->find('https://remote.example/notes/1');

		self::assertNotNull($found);
		self::assertSame('https://remote.example/notes/1', $found->objectId);
		self::assertSame('alice', $found->authorId);
		self::assertSame('content of https://remote.example/notes/1', $found->content);
		self::assertSame('2026-11-11T10:00:00Z', Time::format($found->publishedAt));
		self::assertSame('2026-11-11T10:05:00Z', Time::format($found->fetchedAt));
		self::assertNull($found->contentWarning);
		self::assertNull($found->inReplyToId);
		self::assertNull($repository->find('https://remote.example/notes/missing'));
	}

	public function testUpsertRefreshesOneObjectInsteadOfDuplicatingIt(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->post('https://remote.example/notes/1', 'alice'));
		$repository->upsert($this->post(
			'https://remote.example/notes/1',
			'alice',
			'content of https://remote.example/notes/1',
			'2026-11-11T10:01:00Z',
			'2026-11-11T10:06:00Z',
		));

		$found = $repository->find('https://remote.example/notes/1');

		self::assertNotNull($found);
		self::assertSame('2026-11-11T10:01:00Z', Time::format($found->publishedAt));
		self::assertSame('2026-11-11T10:06:00Z', Time::format($found->fetchedAt));
	}

	public function testChildrenAreDirectlyScopedOrderedAndLimited(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->post('https://remote.example/notes/root', 'alice'));
		$repository->upsert($this->post(
			'https://remote.example/notes/reply-late',
			'bob',
			'late',
			'2026-11-11T10:03:00Z',
			'2026-11-11T10:05:00Z',
			'https://remote.example/notes/root',
		));
		$repository->upsert($this->post(
			'https://remote.example/notes/reply-early',
			'carol',
			'early',
			'2026-11-11T10:02:00Z',
			'2026-11-11T10:05:00Z',
			'https://remote.example/notes/root',
		));
		$repository->upsert($this->post(
			'https://remote.example/notes/grandchild',
			'dave',
			'grandchild',
			'2026-11-11T10:04:00Z',
			'2026-11-11T10:05:00Z',
			'https://remote.example/notes/reply-early',
		));

		$children = $repository->children('https://remote.example/notes/root', 10);

		self::assertSame(
			['https://remote.example/notes/reply-early', 'https://remote.example/notes/reply-late'],
			array_map(static fn (RemoteThreadPost $post): string => $post->objectId, $children),
		);
		self::assertCount(1, $repository->children('https://remote.example/notes/root', 1));
		self::assertSame([], $repository->children('https://remote.example/notes/root', 0));
		self::assertSame([], $repository->children('https://remote.example/notes/missing', 10));
	}

	public function testDeleteFetchedBeforeExpiresOnlyOlderNodesAndHonoursTheLimit(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->post('https://remote.example/notes/old-a', 'alice', 'old', '2026-11-11T10:00:00Z', '2026-11-11T10:00:00Z'));
		$repository->upsert($this->post('https://remote.example/notes/old-b', 'alice', 'old', '2026-11-11T10:00:00Z', '2026-11-11T10:01:00Z'));
		$repository->upsert($this->post('https://remote.example/notes/new', 'alice', 'new', '2026-11-11T10:01:00Z', '2026-11-11T10:10:00Z'));

		self::assertSame(2, $repository->countFetchedBefore(Time::parse('2026-11-11T10:05:00Z')));
		self::assertSame(1, $repository->deleteFetchedBefore(Time::parse('2026-11-11T10:05:00Z'), 1));
		self::assertNull($repository->find('https://remote.example/notes/old-a'));
		self::assertNotNull($repository->find('https://remote.example/notes/old-b'));
		self::assertNotNull($repository->find('https://remote.example/notes/new'));
		self::assertSame(1, $repository->deleteFetchedBefore(Time::parse('2026-11-11T10:05:00Z')));
		self::assertNull($repository->find('https://remote.example/notes/old-b'));
		self::assertSame(0, $repository->deleteFetchedBefore(Time::parse('2026-11-11T10:00:00Z')));
	}

	private function post(
		string $objectId,
		string $authorId,
		?string $content = null,
		string $publishedAt = '2026-11-11T10:00:00Z',
		string $fetchedAt = '2026-11-11T10:05:00Z',
		?string $inReplyToId = null,
	): RemoteThreadPost {
		return new RemoteThreadPost(
			objectId: $objectId,
			authorId: $authorId,
			content: $content ?? 'content of ' . $objectId,
			publishedAt: Time::parse($publishedAt),
			fetchedAt: Time::parse($fetchedAt),
			inReplyToId: $inReplyToId,
		);
	}
}
