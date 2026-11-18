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
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The native profile timeline, which replaces a scan of the public page for
 * local profiles. Its audience rules must match the single-post route.
 */
final class AccountStatusesTest extends TestCase
{
	private Application $app;

	protected function setUp(): void
	{
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-18T10:00:00Z')),
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example'],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob', 'carol']),
		);

		$this->createPost('bob', 'public post', 'public');
		$this->createPost('bob', 'followers post', 'followers');
		$this->createPost('bob', 'direct post', 'direct', ['alice']);
	}

	public function testAnonymousViewerSeesOnlyPublicAndUnlistedPosts(): void
	{
		$response = $this->app->handle(new Request('GET', '/api/v1/accounts/bob/statuses'));

		self::assertSame(200, $response->status);
		$posts = $response->decodedBody()['posts'];
		self::assertSame(['public post'], $this->contents($posts));
	}

	public function testAcceptedFollowerAlsoSeesFollowersOnlyPosts(): void
	{
		$this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));

		$posts = $this->app->handle(new Request(
			'GET',
			'/api/v1/accounts/bob/statuses',
			userId: 'alice',
		))->decodedBody()['posts'];

		self::assertSame(['followers post', 'public post'], $this->contents($posts));
	}

	public function testAuthorSeesOwnPostsButNotUnrelatedDirectMessages(): void
	{
		$posts = $this->app->handle(new Request(
			'GET',
			'/api/v1/accounts/bob/statuses',
			userId: 'bob',
		))->decodedBody()['posts'];

		self::assertSame(['followers post', 'public post'], $this->contents($posts));
	}

	public function testNonFollowerDoesNotSeeFollowersOnlyPosts(): void
	{
		$posts = $this->app->handle(new Request(
			'GET',
			'/api/v1/accounts/bob/statuses',
			userId: 'carol',
		))->decodedBody()['posts'];

		self::assertSame(['public post'], $this->contents($posts));
	}

	public function testInvalidCursorIsRejected(): void
	{
		$response = $this->app->handle(new Request(
			'GET',
			'/api/v1/accounts/bob/statuses',
			['before' => 'not-a-timestamp'],
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_cursor', $response->decodedBody()['error']['code']);
	}

	/**
	 * Posts written within the same frozen second have no total order of their
	 * own, so assertions compare the set rather than the row order.
	 *
	 * @param list<array<string, mixed>> $posts
	 *
	 * @return list<string>
	 */
	private function contents(array $posts): array
	{
		$contents = array_column($posts, 'content');
		sort($contents);

		return $contents;
	}

	/**
	 * @param list<string> $recipients
	 */
	private function createPost(string $authorId, string $content, string $visibility, array $recipients = []): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => $content,
				'visibility' => $visibility,
				'recipient_ids' => $recipients,
			], JSON_THROW_ON_ERROR),
			userId: $authorId,
		));

		self::assertSame(201, $response->status, (string) $response->body);
	}
}
