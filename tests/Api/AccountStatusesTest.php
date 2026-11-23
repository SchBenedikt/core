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
	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-18T10:00:00Z'));
		$this->app = Application::inMemory(
			clock: $this->clock,
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

	public function testPaginationPreservesPostsWithIdenticalTimestampsAndSkipsHiddenRows(): void
	{
		for ($index = 0; $index < 12; $index++) {
			$this->createPost('bob', 'public ' . $index, 'public');
			$this->createPost('bob', 'hidden ' . $index, 'followers');
		}

		$cursor = null;
		$contents = [];
		$seen = [];
		do {
			$query = ['limit' => '2'];
			if ($cursor !== null) {
				$query['before'] = $cursor;
			}
			$body = $this->app->handle(new Request('GET', '/api/v1/accounts/bob/statuses', $query))->decodedBody();
			foreach ($body['posts'] as $post) {
				self::assertNotContains($post['id'], $seen, 'Pages must not repeat a post.');
				$seen[] = $post['id'];
				$contents[] = $post['content'];
			}
			$cursor = $body['next_before'];
		} while ($cursor !== null);

		self::assertCount(13, $contents); // the setup's original public post plus twelve new public posts
		self::assertCount(13, array_unique($contents));
		self::assertNotContains('hidden 0', $contents);
	}

	public function testMalformedCompoundCursorIsRejected(): void
	{
		$response = $this->app->handle(new Request(
			'GET',
			'/api/v1/accounts/bob/statuses',
			['before' => 'v1.bm90LWpzb24'],
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_cursor', $response->decodedBody()['error']['code']);
	}

	public function testAnonymousHiddenOnlyPagesStillAdvanceToOlderPublicPosts(): void
	{
		$this->clock->advance(1);
		for ($index = 0; $index < 8; $index++) {
			$this->createPost('bob', 'new hidden ' . $index, 'followers');
		}

		$first = $this->app->handle(new Request('GET', '/api/v1/accounts/bob/statuses', ['limit' => '2']))->decodedBody();
		self::assertSame([], $first['posts']);
		self::assertNotNull($first['next_before']);

		$second = $this->app->handle(new Request('GET', '/api/v1/accounts/bob/statuses', [
			'limit' => '2', 'before' => $first['next_before'],
		]))->decodedBody();
		self::assertSame([], $second['posts']);
		self::assertNotNull($second['next_before']);

		$third = $this->app->handle(new Request('GET', '/api/v1/accounts/bob/statuses', [
			'limit' => '2', 'before' => $second['next_before'],
		]))->decodedBody();
		self::assertSame(['public post'], array_column($third['posts'], 'content'));
		self::assertNull($third['next_before']);
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
