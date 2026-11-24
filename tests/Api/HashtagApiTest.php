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
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/v1/timelines/tag/{tag}` against the real kernel.
 *
 * Tags are exact, normalized and public: a post cannot enter the timeline
 * through a prefix, a different case, a restricted visibility or a tombstone.
 */
final class HashtagApiTest extends TestCase
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

	public function testTagTimelineIsExactPublicAndCursorAware(): void
	{
		$old = $this->post('Hello #DevOps and #php');
		$unlisted = $this->post('another #devops', 'unlisted', 'bob');
		$this->post('#devops for followers', 'followers');
		$this->post('#devops direct', 'direct', recipientIds: ['bob']);
		$this->post('#development is not #devops_extra');
		$deleted = $this->post('#devops gone');
		$this->app->handle(new Request('DELETE', '/api/v1/posts/' . $deleted, userId: 'alice'));

		$response = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops'));

		self::assertSame(200, $response->status);
		self::assertSame('devops', $response->decodedBody()['tag']);
		// Unlisted is readable by link but never listed by a tag.
		self::assertSame(
			[$old],
			array_column($response->decodedBody()['posts'], 'id'),
		);
		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $unlisted))->status);

		$limited = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', ['limit' => '1']));
		self::assertSame([$old], array_column($limited->decodedBody()['posts'], 'id'));

		$page = $this->app->handle(new Request(
			'GET',
			'/api/v1/timelines/tag/devops',
			['before' => '2026-11-11T10:00:00Z'],
		));
		self::assertSame([], $page->decodedBody()['posts']);

		$cased = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/%23DevOps'));
		self::assertSame(200, $cased->status);
		self::assertSame('devops', $cased->decodedBody()['tag']);
		self::assertSame([$old], array_column($cased->decodedBody()['posts'], 'id'));
	}

	public function testMalformedAndUnknownTagsAreEmptyOrRejected(): void
	{
		$unknown = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/missing'));
		self::assertSame(200, $unknown->status);
		self::assertSame([], $unknown->decodedBody()['posts']);

		$invalid = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/not%20a%20tag'));
		self::assertSame(400, $invalid->status);
		self::assertSame('invalid_tag', $invalid->decodedBody()['error']['code']);

		$invalidCursor = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', ['before' => 'soon']));
		self::assertSame(400, $invalidCursor->status);
		self::assertSame('invalid_cursor', $invalidCursor->decodedBody()['error']['code']);
	}

	public function testPageResponseProvidesTheCursorForTheNextVisiblePost(): void
	{
		$oldest = $this->post('#devops oldest');
		$middle = $this->post('#devops middle');
		$newest = $this->post('#devops newest');

		$first = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', ['limit' => '1']))->decodedBody();
		self::assertSame([$newest], array_column($first['posts'], 'id'));
		self::assertTrue($first['has_more']);
		self::assertSame($newest, $first['next_before_id']);

		$second = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', [
			'limit' => '1',
			'before' => $first['next_before'],
			'before_id' => $first['next_before_id'],
		]))->decodedBody();
		self::assertSame([$middle], array_column($second['posts'], 'id'));
		self::assertTrue($second['has_more']);

		$third = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', [
			'limit' => '1',
			'before' => $second['next_before'],
			'before_id' => $second['next_before_id'],
		]))->decodedBody();
		self::assertSame([$oldest], array_column($third['posts'], 'id'));
		self::assertFalse($third['has_more']);
		self::assertNull($third['next_before']);
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
