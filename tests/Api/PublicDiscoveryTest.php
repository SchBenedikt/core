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
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * Readability versus discoverability.
 *
 * An unlisted post must stay reachable by its URL — that is what makes it
 * shareable — while never appearing in a listing a stranger can page through:
 * the public timeline, a hashtag timeline, search or the actor outbox. The
 * anonymous viewer matrix is asserted at the HTTP boundary because that is
 * where the promise is observable.
 */
final class PublicDiscoveryTest extends TestCase
{
	private static RsaKeyPair $key;

	private Application $app;

	private string $public;

	private string $unlisted;

	private string $followers;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
					'instance.url' => 'https://social.example',
					'mastodon.api' => 'true',
				],
			]),
			signingKey: self::$key,
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
		);

		$this->public = $this->post('public #devops hello', 'public');
		$this->unlisted = $this->post('unlisted #devops hello', 'unlisted');
		$this->followers = $this->post('followers #devops hello', 'followers');
	}

	public function testThePublicTimelineListsOnlyPublicPosts(): void
	{
		$response = $this->app->handle(new Request('GET', '/api/v1/timelines/public'));

		self::assertSame(200, $response->status);
		self::assertSame([$this->public], array_column($response->decodedBody()['posts'], 'id'));
	}

	public function testSignedInPublicFeedsHideRepliesAndAnonymousReadersCanStillSeeThem(): void
	{
		$root = $this->post('root #devops', 'public');
		$replyResponse = $this->createPost('reply #devops', 'public', $root);
		self::assertSame(201, $replyResponse->status);
		$reply = $replyResponse->decodedBody()['post']['id'];

		$anonymous = $this->app->handle(new Request('GET', '/api/v1/timelines/public'));
		$signedIn = $this->app->handle(new Request('GET', '/api/v1/timelines/public', userId: 'bob'));
		$tag = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops', userId: 'bob'));

		self::assertContains($reply, array_column($anonymous->decodedBody()['posts'], 'id'));
		self::assertNotContains($reply, array_column($signedIn->decodedBody()['posts'], 'id'));
		self::assertNotContains($reply, array_column($tag->decodedBody()['posts'], 'id'));
	}

	public function testTheHashtagTimelineListsOnlyPublicPosts(): void
	{
		$response = $this->app->handle(new Request('GET', '/api/v1/timelines/tag/devops'));

		self::assertSame(200, $response->status);
		self::assertSame([$this->public], array_column($response->decodedBody()['posts'], 'id'));
	}

	public function testSearchListsOnlyPublicPosts(): void
	{
		$response = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/search',
			query: ['q' => 'hello', 'type' => 'statuses'],
			userId: 'alice',
			scopes: ['read:search'],
		));

		self::assertSame(200, $response->status);
		self::assertSame([$this->public], array_column($response->decodedBody()['statuses'], 'id'));
	}

	public function testTheOutboxEnumeratesOnlyPublicPosts(): void
	{
		$response = $this->app->handle(new Request('GET', '/@alice/outbox'));

		self::assertSame(200, $response->status);
		self::assertSame(1, $response->decodedBody()['totalItems']);

		$page = $this->app->handle(new Request('GET', '/@alice/outbox', ['page' => '1']));
		self::assertSame(200, $page->status);
		self::assertStringContainsString('public #devops hello', (string) json_encode($page->decodedBody()));
		self::assertStringNotContainsString('unlisted #devops hello', (string) json_encode($page->decodedBody()));
		self::assertStringNotContainsString('followers #devops hello', (string) json_encode($page->decodedBody()));
	}

	public function testTheOutboxPageAndCountExcludeUnlistedPosts(): void
	{
		$page = $this->app->handle(new Request('GET', '/@alice/outbox', ['page' => '1']));

		self::assertSame(200, $page->status);
		self::assertStringContainsString('public #devops hello', (string) json_encode($page->decodedBody()));
		self::assertStringNotContainsString('unlisted #devops hello', (string) json_encode($page->decodedBody()));
		self::assertSame(
			1,
			$this->app->handle(new Request('GET', '/@alice/outbox'))->decodedBody()['totalItems'],
		);
	}

	public function testAnUnlistedPostStaysReadableByItsLink(): void
	{
		foreach ([null, 'bob'] as $viewer) {
			$response = $this->app->handle(new Request('GET', '/api/v1/posts/' . $this->unlisted, userId: $viewer));

			self::assertSame(200, $response->status);
			self::assertSame('unlisted', $response->decodedBody()['post']['visibility']);
			self::assertSame('unlisted #devops hello', $response->decodedBody()['post']['content']);
		}
	}

	public function testUnlistedAndFollowersPostsStayInTheAuthorHomeTimeline(): void
	{
		$response = $this->app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));

		self::assertSame(200, $response->status);
		$ids = array_column($response->decodedBody()['posts'], 'id');
		self::assertContains($this->public, $ids);
		self::assertContains($this->unlisted, $ids);
		self::assertContains($this->followers, $ids);
	}

	public function testAStrangerOnTheHomeTimelineDoesNotSeeTheUnlistedPost(): void
	{
		$response = $this->app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'bob'));

		self::assertSame(200, $response->status);
		self::assertSame([], $response->decodedBody()['posts']);
	}

	private function post(string $content, string $visibility): string
	{
		$response = $this->createPost($content, $visibility);
		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody()['post']['id'];
	}

	private function createPost(string $content, string $visibility, ?string $inReplyToId = null): Response
	{
		$body = ['content' => $content, 'visibility' => $visibility];
		if ($inReplyToId !== null) {
			$body['in_reply_to_id'] = $inReplyToId;
		}

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}
}
