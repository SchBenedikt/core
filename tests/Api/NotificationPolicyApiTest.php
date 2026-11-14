<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Notification\NotificationPolicy;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The neutral notification-policy surface: held request inbox, accept and
 * dismiss lifecycle, per-account policy and thread-root conversation mutes.
 */
final class NotificationPolicyApiTest extends TestCase
{
	private Application $app;

	protected function setUp(): void
	{
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-14T10:00:00Z')),
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example'],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob', 'carol']),
			notificationDefaultPolicy: NotificationPolicy::default(),
		);
	}

	public function testAHeldSenderRequestCanBeAcceptedAndReleasesTheEvent(): void
	{
		$root = $this->post('alice', 'a public root');
		$this->post('bob', 'a stranger reply', $root);

		$page = $this->app->handle(new Request('GET', '/api/v1/notifications', userId: 'alice'));
		self::assertSame(0, $page->decodedBody()['unread_count']);
		self::assertSame([], $page->decodedBody()['notifications']);

		$requests = $this->app->handle(new Request('GET', '/api/v1/notifications/requests', userId: 'alice'));
		self::assertSame(200, $requests->status);
		self::assertCount(1, $requests->decodedBody()['requests']);
		$request = $requests->decodedBody()['requests'][0];
		self::assertSame('bob', $request['actor_id']);
		self::assertSame('held', $request['status']);
		self::assertSame('stranger', $request['category']);

		$accepted = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/requests/' . rawurlencode($request['id']) . '/accept',
			userId: 'alice',
		));
		self::assertSame(200, $accepted->status);
		self::assertSame(1, $accepted->decodedBody()['released']);
		self::assertSame('allowed', $accepted->decodedBody()['request']['status']);

		self::assertSame(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'alice'),
		)->decodedBody()['count']);
		self::assertCount(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications', userId: 'alice'),
		)->decodedBody()['notifications']);

		$empty = $this->app->handle(new Request('GET', '/api/v1/notifications/requests', userId: 'alice'));
		self::assertSame([], $empty->decodedBody()['requests']);
	}

	public function testDismissSuppressesAStrangerAndRevokeRestoresTheCategoryPolicy(): void
	{
		$root = $this->post('alice', 'root');
		$this->post('bob', 'held reply', $root);
		$request = $this->app->handle(new Request('GET', '/api/v1/notifications/requests', userId: 'alice'))
			->decodedBody()['requests'][0];

		$dismissed = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/requests/' . rawurlencode($request['id']) . '/dismiss',
			userId: 'alice',
		));
		self::assertSame(200, $dismissed->status);
		self::assertSame('dismissed', $dismissed->decodedBody()['request']['status']);
		self::assertSame(1, $dismissed->decodedBody()['suppressed']);

		$this->post('bob', 'another reply', $root);
		self::assertSame(0, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'alice'),
		)->decodedBody()['count']);

		$revoked = $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/notifications/requests/' . rawurlencode($request['id']),
			userId: 'alice',
		));
		self::assertSame(['revoked' => true], $revoked->decodedBody());

		$this->post('bob', 'held again', $root);
		self::assertCount(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications/requests', userId: 'alice'),
		)->decodedBody()['requests']);
	}

	public function testPolicyUpdateAndConversationMuteAffectOnlyFutureAlerts(): void
	{
		$root = $this->post('alice', 'root');

		$policy = $this->app->handle(new Request('GET', '/api/v1/notifications/policy', userId: 'alice'));
		self::assertSame('hold', $policy->decodedBody()['policy']['actions']['stranger']);
		self::assertSame(7, $policy->decodedBody()['policy']['new_account_days']);

		$invalid = $this->app->handle($this->jsonRequest('PATCH', '/api/v1/notifications/policy', 'alice', [
			'actions' => ['stranger' => 'silence'],
		]));
		self::assertSame(422, $invalid->status);

		$updated = $this->app->handle($this->jsonRequest('PATCH', '/api/v1/notifications/policy', 'alice', [
			'actions' => ['stranger' => 'allow'],
			'new_account_days' => 30,
		]));
		self::assertSame(200, $updated->status);
		self::assertSame('allow', $updated->decodedBody()['policy']['actions']['stranger']);
		self::assertSame(30, $updated->decodedBody()['policy']['new_account_days']);

		$this->post('bob', 'now visible', $root);
		self::assertSame(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'alice'),
		)->decodedBody()['count']);

		$muted = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/conversations/' . rawurlencode($root) . '/mute',
			userId: 'alice',
		));
		self::assertSame(201, $muted->status);
		self::assertSame($root, $muted->decodedBody()['conversation']['conversation_id']);

		$this->post('bob', 'muted reply', $root);
		self::assertSame(1, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'alice'),
		)->decodedBody()['count']);

		$unmuted = $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/conversations/' . rawurlencode($root) . '/mute',
			userId: 'alice',
		));
		self::assertSame(['unmuted' => true], $unmuted->decodedBody());

		$this->post('bob', 'visible again', $root);
		self::assertSame(2, $this->app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'alice'),
		)->decodedBody()['count']);
	}

	public function testRequestsAndPolicyAreAuthenticatedAndScoped(): void
	{
		foreach ([
			['GET', '/api/v1/notifications/requests'],
			['GET', '/api/v1/notifications/policy'],
			['POST', '/api/v1/conversations/post-1/mute'],
		] as [$method, $path]) {
			$response = $this->app->handle(new Request($method, $path));
			self::assertSame(401, $response->status);
		}

		$root = $this->post('alice', 'root');
		$this->post('bob', 'reply', $root);
		$request = $this->app->handle(new Request('GET', '/api/v1/notifications/requests', userId: 'alice'))
			->decodedBody()['requests'][0];

		$foreign = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/requests/' . rawurlencode($request['id']) . '/accept',
			userId: 'carol',
		));
		self::assertSame(404, $foreign->status);
	}

	private function post(string $userId, string $content, ?string $inReplyToId = null): string
	{
		$response = $this->app->handle($this->jsonRequest('POST', '/api/v1/posts', $userId, [
			'content' => $content,
			'in_reply_to_id' => $inReplyToId,
		]));
		self::assertSame(201, $response->status, $response->body);

		return $response->decodedBody()['post']['id'];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function jsonRequest(string $method, string $path, string $userId, array $body): Request
	{
		return new Request(
			method: $method,
			path: $path,
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: $userId,
		);
	}
}
