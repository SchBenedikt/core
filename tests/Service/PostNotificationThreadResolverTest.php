<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Service\Notification\PostNotificationThreadResolver;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class PostNotificationThreadResolverTest extends TestCase
{
	private InMemoryPostRepository $posts;

	private InMemoryRemoteThreadRepository $remoteThreads;

	private PostNotificationThreadResolver $resolver;

	protected function setUp(): void
	{
		$this->posts = new InMemoryPostRepository();
		$this->remoteThreads = new InMemoryRemoteThreadRepository();
		$this->resolver = new PostNotificationThreadResolver($this->posts, $this->remoteThreads);
	}

	public function testLocalRepliesResolveToTheRootAndUnknownRootsStayUnknown(): void
	{
		$this->posts->insert($this->post('root', null));
		$this->posts->insert($this->post('reply', 'root'));
		$this->posts->insert($this->post('nested', 'reply'));

		self::assertSame('root', $this->resolver->rootFor('nested'));
		self::assertSame('root', $this->resolver->rootFor('root'));
		self::assertNull($this->resolver->rootFor('missing'));

		$this->posts->insert($this->post('dangling', 'gone'));
		self::assertNull($this->resolver->rootFor('dangling'));
	}

	public function testCyclesAndCachedRemoteRepliesAreBounded(): void
	{
		$this->posts->insert($this->post('a', 'b'));
		$this->posts->insert($this->post('b', 'a'));
		self::assertNull($this->resolver->rootFor('a'));

		$this->remoteThreads->upsert(new RemoteThreadPost(
			objectId: 'https://remote.example/notes/root',
			authorId: 'https://remote.example/users/bob',
			content: 'root',
			publishedAt: Time::parse('2026-11-14T09:00:00Z'),
			fetchedAt: Time::parse('2026-11-14T10:00:00Z'),
		));
		$this->remoteThreads->upsert(new RemoteThreadPost(
			objectId: 'https://remote.example/notes/reply',
			authorId: 'https://remote.example/users/carol',
			content: 'reply',
			publishedAt: Time::parse('2026-11-14T09:01:00Z'),
			fetchedAt: Time::parse('2026-11-14T10:00:00Z'),
			inReplyToId: 'https://remote.example/notes/root',
		));

		self::assertSame(
			'https://remote.example/notes/root',
			$this->resolver->rootFor('https://remote.example/notes/reply'),
		);
		self::assertNull($this->resolver->rootFor('https://remote.example/notes/missing'));
	}

	private function post(string $id, ?string $inReplyToId): Post
	{
		return new Post(
			id: $id,
			authorId: 'alice',
			content: $id,
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-14T10:00:00Z'),
			inReplyToId: $inReplyToId,
		);
	}
}
