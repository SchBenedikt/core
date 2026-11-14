<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * `followed_tags` and `GET /api/v1/timelines/followed_tags`.
 *
 * Follows are private, normalized and idempotent; the timeline includes only
 * public posts matching a followed tag exactly, since a followed tag must not
 * turn an unlisted post into a discoverable one.
 */
final class FollowedHashtagApiTest extends TestCase
{
	private Application $app;

	private MutableClock $clock;

	protected function setUp(): void
	{
		$this->clock = new MutableClock();
		$this->app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
					'instance.url' => 'https://social.example',
				],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
		);
	}

	public function testFollowListUnfollowAndValidation(): void
	{
		self::assertSame(
			401,
			$this->app->handle(new Request('GET', '/api/v1/followed_tags'))->status,
		);

		$created = $this->follow('#DevOps');
		self::assertSame(201, $created->status, $created->body);
		self::assertSame('devops', $created->decodedBody()['tag']);
		self::assertSame('2026-11-11T10:00:00Z', $created->decodedBody()['created_at']);

		$this->clock->advance(60);
		$again = $this->follow('devops');
		self::assertSame(200, $again->status);
		self::assertSame('2026-11-11T10:00:00Z', $again->decodedBody()['created_at']);

		$list = $this->app->handle(new Request('GET', '/api/v1/followed_tags', userId: 'alice'));
		self::assertSame(200, $list->status);
		self::assertSame(['devops'], array_column($list->decodedBody()['tags'], 'tag'));

		$bob = $this->app->handle(new Request('GET', '/api/v1/followed_tags', userId: 'bob'));
		self::assertSame([], $bob->decodedBody()['tags']);

		$removed = $this->app->handle(new Request(
			'DELETE',
			'/api/v1/followed_tags/%23DevOps',
			userId: 'alice',
		));
		self::assertSame(200, $removed->status);
		self::assertSame('devops', $removed->decodedBody()['tag']);
		self::assertSame([], $this->list()['tags']);

		$repeat = $this->app->handle(new Request(
			'DELETE',
			'/api/v1/followed_tags/devops',
			userId: 'alice',
		));
		self::assertSame(200, $repeat->status);

		$invalid = $this->follow('two words');
		self::assertSame(400, $invalid->status);
		self::assertSame('invalid_tag', $invalid->decodedBody()['error']['code']);

		$missing = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/followed_tags',
			headers: ['Content-Type' => 'application/json'],
			body: '{}',
			userId: 'alice',
		));
		self::assertSame(400, $missing->status);
		self::assertSame('invalid_tag', $missing->decodedBody()['error']['code']);

		$badCursor = $this->app->handle(new Request(
			'GET',
			'/api/v1/followed_tags',
			['before' => 'soon'],
			userId: 'alice',
		));
		self::assertSame(400, $badCursor->status);
		self::assertSame('invalid_cursor', $badCursor->decodedBody()['error']['code']);

		$badTagCursor = $this->app->handle(new Request(
			'GET',
			'/api/v1/followed_tags',
			['before_tag' => 'two words'],
			userId: 'alice',
		));
		self::assertSame(400, $badTagCursor->status);
		self::assertSame('invalid_tag', $badTagCursor->decodedBody()['error']['code']);
	}

	public function testFollowedTimelineIsExactPublicAndCursorAware(): void
	{
		$this->follow('devops');
		$this->clock->advance(1);
		$this->follow('php');

		$old = $this->post('Hello #DevOps and #php');
		$unlisted = $this->post('another #php', 'unlisted', 'bob');
		$this->post('#devops for followers', 'followers');
		$this->post('#devops direct', 'direct', recipientIds: ['bob']);
		$this->post('#development is not #devops_extra');
		$deleted = $this->post('#devops gone');
		$this->app->handle(new Request('DELETE', '/api/v1/posts/' . $deleted, userId: 'alice'));

		$timeline = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			userId: 'alice',
		));

		self::assertSame(200, $timeline->status);
		self::assertSame(['php', 'devops'], $timeline->decodedBody()['tags']);
		self::assertSame(
			[$old],
			array_column($timeline->decodedBody()['posts'], 'id'),
		);
		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $unlisted))->status);

		$limited = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			['limit' => '1'],
			userId: 'alice',
		));
		self::assertSame([$old], array_column($limited->decodedBody()['posts'], 'id'));

		$latest = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			userId: 'alice',
		))->decodedBody()['posts'][0]['published_at'];
		$page = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			['before' => $latest],
			userId: 'alice',
		));
		self::assertSame([], array_column($page->decodedBody()['posts'], 'id'));

		$this->app->handle(new Request('DELETE', '/api/v1/followed_tags/devops', userId: 'alice'));
		$phpOnly = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			userId: 'alice',
		));
		self::assertSame(['php'], $phpOnly->decodedBody()['tags']);
		self::assertSame([$old], array_column($phpOnly->decodedBody()['posts'], 'id'));

		$bobEmpty = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			userId: 'bob',
		));
		self::assertSame([], $bobEmpty->decodedBody()['tags']);
		self::assertSame([], $bobEmpty->decodedBody()['posts']);
	}

	public function testFollowLimitIsEnforcedWithItsOwnCode(): void
	{
		for ($index = 0; $index < 100; $index++) {
			self::assertSame(201, $this->follow('tag' . $index)->status);
		}

		$response = $this->follow('overflow');
		self::assertSame(400, $response->status);
		self::assertSame('followed_tag_limit', $response->decodedBody()['error']['code']);
	}

	/**
	 * @return array{tags: list<array{tag: string, created_at: string}>}
	 */
	private function list(): array
	{
		return $this->app->handle(new Request(
			'GET',
			'/api/v1/followed_tags',
			userId: 'alice',
		))->decodedBody();
	}

	private function follow(string $tag): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/followed_tags',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['tag' => $tag], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}

	private function post(
		string $content,
		string $visibility = 'public',
		string $authorId = 'alice',
		array $recipientIds = [],
	): string {
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => $content,
				'visibility' => $visibility,
				'recipient_ids' => $recipientIds,
			], JSON_THROW_ON_ERROR),
			userId: $authorId,
		));

		self::assertSame(201, $response->status, $response->body);

		$this->clock->advance(60);

		return $response->decodedBody()['post']['id'];
	}
}
