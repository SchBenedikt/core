<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class PinServiceTest extends TestCase
{
	private Application $app;

	protected function setUp(): void
	{
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-15T12:00:00Z')),
			config: new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
		);
	}

	public function testPinningIsIdempotentAndKeepsTheFirstPosition(): void
	{
		$post = $this->post('alice');

		$first = $this->pins()->pin('alice', $post->id);
		$second = $this->pins()->pin('alice', $post->id);

		self::assertSame(1, $first->position);
		self::assertSame(1, $second->position);
		self::assertCount(1, $this->pins()->forOwner('alice'));
		self::assertSame($post->id, $this->pins()->forOwner('alice')[0]['post']['id']);
	}

	public function testOnlyOwnPublicPostsCanBePinnedAndTheLimitIsFive(): void
	{
		$foreign = $this->post('bob');
		$followers = $this->post('alice', Visibility::Followers);

		try {
			$this->pins()->pin('alice', $foreign->id);
			self::fail('A foreign post must not be pinned.');
		} catch (PostAccessDeniedException) {
			// expected
		}

		try {
			$this->pins()->pin('alice', $followers->id);
			self::fail('A followers-only post must not be pinned.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('post_id', $exception->fields());
		}

		for ($i = 0; $i < 5; $i++) {
			$this->pins()->pin('alice', $this->post('alice')->id);
		}

		$this->expectException(ValidationException::class);

		$this->pins()->pin('alice', $this->post('alice')->id);
	}

	public function testUnpinningIsIdempotentAndDoesNotDeleteThePost(): void
	{
		$post = $this->post('alice');
		$this->pins()->pin('alice', $post->id);

		$this->pins()->unpin('alice', $post->id);
		$this->pins()->unpin('alice', $post->id);

		self::assertSame([], $this->pins()->forOwner('alice'));
		self::assertSame($post->id, $this->app->posts->getVisible($post->id, 'alice')->id);
	}

	public function testArchivingOrDeletingAPostRemovesItsPin(): void
	{
		$archived = $this->post('alice');
		$deleted = $this->post('alice');
		$this->pins()->pin('alice', $archived->id);
		$this->pins()->pin('alice', $deleted->id);

		$this->app->posts->archive('alice', $archived->id);
		$this->app->posts->delete('alice', $deleted->id);

		self::assertSame([], $this->pins()->forOwner('alice'));
	}

	public function testAnotherOwnerCannotUnpinSomebodyElsesPost(): void
	{
		$post = $this->post('alice');
		$this->pins()->pin('alice', $post->id);

		$this->pins()->unpin('bob', $post->id);

		self::assertCount(1, $this->pins()->forOwner('alice'));
	}

	private function pins(): \AlohaSocial\Core\Service\PinService
	{
		$pins = $this->app->pins;
		self::assertNotNull($pins);

		return $pins;
	}

	private function post(string $authorId, Visibility $visibility = Visibility::Public): Post
	{
		return $this->app->posts->create(new CreatePost(
			authorId: $authorId,
			content: 'A post by ' . $authorId,
			visibility: $visibility,
		));
	}
}
