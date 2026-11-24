<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Post\InMemoryBoostRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class BoostTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryBoostRepository $boosts;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->boosts = new InMemoryBoostRepository();
	}

	public function testBoostAndUnboostAreIdempotent(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'boost me');

		$first = $this->boost($app, $post['id'], 'bob');
		self::assertSame(201, $first->status);
		self::assertSame('bob', $first->decodedBody()['boost']['user_id']);
		self::assertSame($post['id'], $first->decodedBody()['boost']['post_id']);
		self::assertStringStartsWith('boost-', $first->decodedBody()['boost']['id']);
		self::assertSame(1, $this->boosts->countForPost($post['id']));

		$again = $this->boost($app, $post['id'], 'bob');
		self::assertSame(200, $again->status);
		self::assertSame($first->decodedBody()['boost']['id'], $again->decodedBody()['boost']['id']);
		self::assertSame(1, $this->boosts->countForPost($post['id']));

		self::assertSame(204, $this->unboost($app, $post['id'], 'bob')->status);
		self::assertSame(0, $this->boosts->countForPost($post['id']));
		self::assertSame(204, $this->unboost($app, $post['id'], 'bob')->status);
	}

	public function testAStoredBoostAppearsInTheBoostersHomeTimeline(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'boost me');
		$boost = $this->boost($app, $post['id'], 'bob')->decodedBody()['boost'];

		$home = $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'bob'));
		$items = $home->decodedBody()['posts'];

		self::assertSame($boost['id'], $items[0]['id']);
		self::assertSame('boost', $items[0]['kind']);
		self::assertSame('bob', $items[0]['boosted_by']);
		self::assertSame($post['id'], $items[0]['object_id']);
		self::assertSame('boost me', $items[0]['content']);
	}

	public function testBoostRequiresAuthVisibilityAndAPost(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'public');

		self::assertSame(401, $this->boost($app, $post['id'], null)->status);
		self::assertSame(404, $this->boost($app, 'missing', 'bob')->status);

		$private = $this->createPost($app, 'followers', 'followers');
		$refused = $this->boost($app, $private['id'], 'alice');
		self::assertSame(422, $refused->status);
		self::assertSame('validation_failed', $refused->decodedBody()['error']['code']);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example'],
			]),
			boostRepository: $this->boosts,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createPost(Application $app, string $content, string $visibility = 'public'): array
	{
		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(
				['content' => $content, 'visibility' => $visibility],
				JSON_THROW_ON_ERROR,
			),
			userId: 'alice',
		));
		self::assertSame(201, $response->status);

		return $response->decodedBody()['post'];
	}

	private function boost(Application $app, string $postId, ?string $userId): Response
	{
		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts/' . rawurlencode($postId) . '/boost',
			userId: $userId,
		));
	}

	private function unboost(Application $app, string $postId, ?string $userId): Response
	{
		return $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/posts/' . rawurlencode($postId) . '/boost',
			userId: $userId,
		));
	}
}
