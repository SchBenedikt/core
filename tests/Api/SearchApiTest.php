<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class SearchApiTest extends TestCase
{
	private FrozenClock $clock;

	private Application $app;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
		$this->app = Application::inMemory(clock: $this->clock, config: new ArrayConfig([
			InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://social.example',
				'mastodon.api' => 'true',
			],
		]));
	}

	public function testNeutralSearchReturnsIndexedPublicPostsAndATrendWindow(): void
	{
		$this->app->posts->create(new CreatePost('alice', 'hello #php'));
		$this->app->posts->create(new CreatePost('bob', 'hello #social'));
		$this->app->posts->create(new CreatePost('alice', 'private hello', Visibility::Followers));

		$response = $this->app->handle(new Request('GET', '/api/v1/search', ['q' => 'hello']));
		self::assertSame(200, $response->status);
		$body = $response->decodedBody();
		self::assertCount(2, $body['statuses']);
		self::assertSame([], $body['hashtags']);
		self::assertFalse($body['partial']);
		self::assertSame('post:', substr($body['statuses'][0]['id'], 0, 5));
		self::assertNotContains('private hello', array_column($body['statuses'], 'content'));

		$trends = $this->app->handle(new Request('GET', '/api/v1/trends', ['window' => '86400']));
		self::assertSame(200, $trends->status);
		self::assertSame(86_400, $trends->decodedBody()['window_seconds']);
		self::assertSame(['php', 'social'], array_column($trends->decodedBody()['trends'], 'hashtag'));
	}

	public function testCursorIsStableAndRejectsAnotherQuery(): void
	{
		foreach (['one', 'two', 'three'] as $index => $content) {
			$this->clock->advance($index);
			$this->app->posts->create(new CreatePost('alice', 'hello '.$content));
		}

		$first = $this->app->handle(new Request('GET', '/api/v1/search', [
			'q' => 'hello',
			'type' => 'statuses',
			'limit' => '1',
		]))->decodedBody();
		self::assertCount(1, $first['statuses']);
		self::assertNotNull($first['next_cursor']);

		$second = $this->app->handle(new Request('GET', '/api/v1/search', [
			'q' => 'hello',
			'type' => 'statuses',
			'limit' => '1',
			'cursor' => $first['next_cursor'],
		]))->decodedBody();
		self::assertCount(1, $second['statuses']);
		self::assertNotSame($first['statuses'][0]['id'], $second['statuses'][0]['id']);

		$wrongQuery = $this->app->handle(new Request('GET', '/api/v1/search', [
			'q' => 'other',
			'cursor' => $first['next_cursor'],
		]));
		self::assertSame(400, $wrongQuery->status);
		self::assertSame('invalid_cursor', $wrongQuery->decodedBody()['error']['code']);
	}

	public function testMastodonSearchProjectsHashtagsFromTheIndex(): void
	{
		$this->app->posts->create(new CreatePost('alice', 'hello #php'));

		$response = $this->app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/search',
			['q' => 'php', 'type' => 'hashtags'],
			userId: 'alice',
			scopes: ['read:search'],
		));

		self::assertSame(200, $response->status);
		self::assertSame([[
			'name' => 'php',
			'url' => 'https://social.example/tags/php',
			'history' => [],
		]], $response->decodedBody()['hashtags']);
	}

	public function testSearchValidatesInputAndDoesNotRequireAViewer(): void
	{
		self::assertSame(422, $this->app->handle(new Request('GET', '/api/v1/search'))->status);
		self::assertSame(422, $this->app->handle(new Request(
			'GET',
			'/api/v1/search',
			['q' => 'hello', 'type' => 'unknown'],
		))->status);

		$anonymous = $this->app->handle(new Request('GET', '/api/v1/search', ['q' => 'hello']));
		self::assertSame(200, $anonymous->status);
		self::assertSame([], $anonymous->decodedBody()['statuses']);
	}
}
