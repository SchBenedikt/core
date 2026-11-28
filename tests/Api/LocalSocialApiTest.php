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
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FailingNotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * The neutral local interaction surface under `/api/v1`.
 *
 * These tests exercise the real kernel: the public profile and viewer
 * relationship, idempotent local follow/unfollow, notification recording from
 * a follow and a reply, the id cursor, dismiss/clear and the stable error
 * paths. They do not turn on the Mastodon compatibility API.
 */
final class LocalSocialApiTest extends TestCase
{
	private Application $app;

	protected function setUp(): void
	{
		$this->app = $this->app();
	}

	public function testProfileCarriesTheViewerRelationshipAndFollowIsIdempotent(): void
	{
		$anonymous = $this->app->handle(new Request('GET', '/api/v1/accounts/bob'));

		self::assertSame(200, $anonymous->status);
		$account = $anonymous->decodedBody()['account'];
		self::assertSame('bob', $account['id']);
		self::assertSame('bob', $account['handle']);
		self::assertSame('bob', $account['display_name']);
		self::assertSame('https://social.example/@bob', $account['url']);
		self::assertSame(0, $account['followers_count']);
		self::assertSame(0, $account['following_count']);
		self::assertNull($account['relationship']);

		$follow = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $follow->status);
		self::assertSame([
			'following' => true,
			'followed_by' => false,
			'blocking' => false,
			'muting' => false,
		], $follow->decodedBody()['relationship']);

		$viewed = $this->app->handle(new Request('GET', '/api/v1/accounts/bob', userId: 'alice'));
		self::assertSame(1, $viewed->decodedBody()['account']['followers_count']);
		self::assertTrue($viewed->decodedBody()['account']['relationship']['following']);

		$repeat = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $repeat->status);
		self::assertTrue($repeat->decodedBody()['relationship']['following']);
		self::assertSame(1, $this->app->handle(
			new Request('GET', '/api/v1/accounts/bob'),
		)->decodedBody()['account']['followers_count']);

		$unfollow = $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $unfollow->status);
		self::assertFalse($unfollow->decodedBody()['relationship']['following']);

		$repeatUnfollow = $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $repeatUnfollow->status);
		self::assertFalse($repeatUnfollow->decodedBody()['relationship']['following']);
		self::assertSame(0, $this->app->handle(
			new Request('GET', '/api/v1/accounts/bob'),
		)->decodedBody()['account']['followers_count']);
	}

	public function testPostCreationKeepsTheSelectedLanguage(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'hallo', 'language' => 'de'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $response->status);
		self::assertSame('de', $response->decodedBody()['post']['language']);
	}

	public function testPostCreationWithAnIdempotencyKeyReplaysTheSamePost(): void
	{
		$body = json_encode(['content' => 'retry me'], JSON_THROW_ON_ERROR);
		$headers = [
			'Content-Type' => 'application/json',
			'Idempotency-Key' => 'native-key-1',
		];
		$first = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: $headers,
			body: $body,
			userId: 'alice',
		));
		$second = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: $headers,
			body: $body,
			userId: 'alice',
		));

		self::assertSame(201, $first->status);
		self::assertSame(201, $second->status);
		self::assertSame($first->decodedBody()['post']['id'], $second->decodedBody()['post']['id']);
	}

	public function testPostCreationWithAnIdempotencyKeyAndADifferentPayloadConflicts(): void
	{
		$headers = [
			'Content-Type' => 'application/json',
			'Idempotency-Key' => 'native-key-2',
		];
		$first = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: $headers,
			body: json_encode(['content' => 'original'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		$second = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: $headers,
			body: json_encode(['content' => 'changed'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $first->status);
		self::assertSame(409, $second->status);
		self::assertSame('idempotency_conflict', $second->decodedBody()['error']['code']);
	}

	public function testProfileAndFollowRejectUnknownUnauthenticatedAndSelfTargets(): void
	{
		self::assertSame(404, $this->app->handle(
			new Request('GET', '/api/v1/accounts/ghost'),
		)->status);

		$anonymous = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
		));
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);

		$unknown = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/ghost/follow',
			userId: 'alice',
		));
		self::assertSame(404, $unknown->status);

		$self = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/alice/follow',
			userId: 'alice',
		));
		self::assertSame(422, $self->status);
		self::assertSame('validation_failed', $self->decodedBody()['error']['code']);
		self::assertArrayHasKey('id', $self->decodedBody()['error']['fields']);
	}

	public function testNotificationsPageDismissAndClear(): void
	{
		// A follow and a reply are the two local events this surface records.
		$this->follow('alice', 'bob');

		$postResponse = $this->createPost('bob', 'hello');
		$post = $postResponse->decodedBody()['post']['id'];
		$reply = $this->createPost('alice', 'hello back', $post);
		self::assertSame(201, $reply->status);
		$replyId = $reply->decodedBody()['post']['id'];

		$page = $this->app->handle(new Request('GET', '/api/v1/notifications', userId: 'bob'));
		self::assertSame(200, $page->status);
		$body = $page->decodedBody();
		self::assertSame(2, $body['unread_count']);
		self::assertCount(2, $body['notifications']);

		$byType = [];
		foreach ($body['notifications'] as $notification) {
			$byType[$notification['type']] = $notification;
			self::assertSame('alice', $notification['actor_id']);
			self::assertFalse($notification['read']);
			self::assertArrayHasKey('created_at', $notification);
		}

		self::assertArrayHasKey('follow', $byType);
		self::assertArrayHasKey('mention', $byType);
		self::assertNull($byType['follow']['post_id']);
		self::assertSame($replyId, $byType['mention']['post_id']);

		$firstPage = $this->app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			['limit' => '1'],
			userId: 'bob',
		));
		self::assertSame(2, $firstPage->decodedBody()['unread_count']);
		self::assertCount(1, $firstPage->decodedBody()['notifications']);
		$cursor = $firstPage->decodedBody()['notifications'][0]['id'];

		$secondPage = $this->app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			['limit' => '1', 'before' => $cursor],
			userId: 'bob',
		));
		self::assertSame(200, $secondPage->status);
		self::assertCount(1, $secondPage->decodedBody()['notifications']);
		self::assertNotSame(
			$cursor,
			$secondPage->decodedBody()['notifications'][0]['id'],
		);

		$count = $this->app->handle(new Request('GET', '/api/v1/notifications/unread_count', userId: 'bob'));
		self::assertSame(['count' => 2], $count->decodedBody());

		$dismiss = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/' . rawurlencode($cursor) . '/dismiss',
			userId: 'bob',
		));
		self::assertSame(200, $dismiss->status);
		self::assertSame(['dismissed' => true], $dismiss->decodedBody());

		$repeat = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/' . rawurlencode($cursor) . '/dismiss',
			userId: 'bob',
		));
		self::assertSame(200, $repeat->status);

		self::assertSame(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'bob'),
		)->decodedBody()['count']);
		self::assertCount(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications', userId: 'bob'),
		)->decodedBody()['notifications']);

		$foreign = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/' . rawurlencode($cursor) . '/dismiss',
			userId: 'carol',
		));
		self::assertSame(404, $foreign->status);

		$missing = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/missing/dismiss',
			userId: 'bob',
		));
		self::assertSame(404, $missing->status);

		$clear = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/clear',
			userId: 'bob',
		));
		self::assertSame(200, $clear->status);
		self::assertSame(['cleared' => 1], $clear->decodedBody());
		self::assertSame(['count' => 0], $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'bob'),
		)->decodedBody());
		self::assertSame([], $this->app->handle(
			new Request('GET', '/api/v1/notifications', userId: 'bob'),
		)->decodedBody()['notifications']);

		$clearAgain = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/clear',
			userId: 'bob',
		));
		self::assertSame(0, $clearAgain->decodedBody()['cleared']);
	}

	public function testNotificationsRejectAnonymousForeignCursorsAndUnavailableStorage(): void
	{
		$this->follow('alice', 'bob');

		self::assertSame(401, $this->app->handle(
			new Request('GET', '/api/v1/notifications'),
		)->status);

		$notifications = $this->app->handle(
			new Request('GET', '/api/v1/notifications', userId: 'bob'),
		)->decodedBody();
		$foreignCursor = $notifications['notifications'][0]['id'];

		$foreign = $this->app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			['before' => $foreignCursor],
			userId: 'carol',
		));
		self::assertSame(422, $foreign->status);
		self::assertSame('validation_failed', $foreign->decodedBody()['error']['code']);

		$unknown = $this->app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			['before' => 'missing'],
			userId: 'bob',
		));
		self::assertSame(422, $unknown->status);

		$broken = $this->app(notificationRepository: new FailingNotificationRepository());
		$response = $broken->handle(new Request('GET', '/api/v1/notifications', userId: 'bob'));
		self::assertSame(503, $response->status);
		self::assertSame('notifications_unavailable', $response->decodedBody()['error']['code']);
	}

	private function app(?FailingNotificationRepository $notificationRepository = null): Application
	{
		return Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
					'instance.url' => 'https://social.example',
				],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob', 'carol']),
			notificationRepository: $notificationRepository,
		);
	}

	private function follow(string $followerId, string $targetId): Response
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/' . rawurlencode($targetId) . '/follow',
			userId: $followerId,
		));
		self::assertSame(200, $response->status);

		return $response;
	}

	private function createPost(string $authorId, string $content, ?string $inReplyToId = null): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => $content,
				'in_reply_to_id' => $inReplyToId,
			], JSON_THROW_ON_ERROR),
			userId: $authorId,
		));
	}
}
