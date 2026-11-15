<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Story\InMemoryStoryRepository;
use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class StoryApiTest extends TestCase
{
	private const ALICE = 'alice';

	public function testTheOwnerCanReadViewReactReplyAndDeleteAStory(): void
	{
		$repository = new InMemoryStoryRepository();
		$repository->insert($this->story());
		$app = $this->app(true, $repository);

		$feed = $app->handle($this->request('GET', '/api/v1/stories'));
		self::assertSame(200, $feed->status);
		self::assertCount(1, $feed->decodedBody()['stories']);

		$show = $app->handle($this->request('GET', '/api/v1/stories/s1'));
		self::assertSame(200, $show->status);
		self::assertSame('s1', $show->decodedBody()['story']['id']);

		$view = $app->handle($this->request('POST', '/api/v1/stories/s1/views'));
		self::assertSame(204, $view->status);
		$viewers = $app->handle($this->request('GET', '/api/v1/stories/s1/viewers'));
		self::assertSame(200, $viewers->status);
		self::assertCount(1, $viewers->decodedBody()['viewers']);

		$reaction = $app->handle($this->request('POST', '/api/v1/stories/s1/reactions', ['emoji' => '❤️']));
		self::assertSame(201, $reaction->status);
		$reactions = $app->handle($this->request('GET', '/api/v1/stories/s1/reactions'));
		self::assertCount(1, $reactions->decodedBody()['reactions']);

		$reply = $app->handle($this->request('POST', '/api/v1/stories/s1/replies', ['content' => 'nice']));
		self::assertSame(201, $reply->status);
		$replies = $app->handle($this->request('GET', '/api/v1/stories/s1/replies'));
		self::assertSame('nice', $replies->decodedBody()['replies'][0]['content']);

		$delete = $app->handle($this->request('DELETE', '/api/v1/stories/s1'));
		self::assertSame(204, $delete->status);
		self::assertSame(404, $app->handle($this->request('GET', '/api/v1/stories/s1'))->status);
	}

	public function testAStoryIsPrivateAndAnonymousRequestsAreRejected(): void
	{
		$repository = new InMemoryStoryRepository();
		$repository->insert($this->story());
		$app = $this->app(true, $repository);

		$anonymous = $app->handle(new Request('GET', '/api/v1/stories/s1'));
		self::assertSame(401, $anonymous->status);

		$other = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/stories/s1',
			userId: 'bob',
		));
		self::assertSame(404, $other->status);
	}

	public function testDisabledStoriesAnswer503(): void
	{
		$app = $this->app(false, new InMemoryStoryRepository());

		$response = $app->handle($this->request('GET', '/api/v1/stories'));

		self::assertSame(503, $response->status);
		self::assertSame('stories_disabled', $response->decodedBody()['error']['code']);
	}

	public function testCreatingAStoryWithoutAnAttachmentIsValidated(): void
	{
		$app = $this->app(true, new InMemoryStoryRepository());

		$response = $app->handle($this->request('POST', '/api/v1/stories', ['caption' => 'hi']));

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('attachment_id', $response->decodedBody()['error']['fields']);
	}

	private function app(bool $enabled, InMemoryStoryRepository $repository): Application
	{
		return Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-15T12:00:00Z')),
			config: new ArrayConfig(['social' => [
				'instance.url' => 'https://social.example',
				'stories.enabled' => $enabled ? 'true' : 'false',
			]]),
			storyRepository: $repository,
		);
	}

	private function story(): Story
	{
		return new Story(
			's1',
			self::ALICE,
			true,
			'https://social.example/stories/s1',
			'caption',
			Time::parse('2026-11-15T11:00:00Z'),
			Time::parse('2026-11-16T11:00:00Z'),
			'a1',
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function request(string $method, string $path, array $body = []): Request
	{
		return new Request(
			method: $method,
			path: $path,
			headers: $body === [] ? [] : ['Content-Type' => 'application/json'],
			body: $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		);
	}
}
