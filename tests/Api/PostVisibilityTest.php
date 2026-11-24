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
use PHPUnit\Framework\TestCase;

/**
 * A post's visibility decides who may read it by id.
 *
 * Regression: a followers-only post used to be readable by anyone who knew
 * its id, because the single-post handler never looked at the viewer.
 */
final class PostVisibilityTest extends TestCase
{
	private Application $app;

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
			localActors: new InMemoryActorDirectory(['alice', 'bob', 'carol']),
		);
	}

	public function testAFollowersOnlyPostIsNotReadableByOthers(): void
	{
		$id = $this->post('just for followers', 'followers')->decodedBody()['post']['id'];

		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);
		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'bob'))->status);
		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'alice'))->status);
	}

	public function testALocalFollowerCanReadAFollowersOnlyPostButOthersCannot(): void
	{
		$id = $this->post('only for followers', 'followers', 'bob')->decodedBody()['post']['id'];

		$follow = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/accounts/bob/follow',
			userId: 'alice',
			scopes: ['write:follows'],
		));
		self::assertSame(200, $follow->status);

		self::assertSame(200, $this->app->handle(new Request(
			'GET',
			'/api/v1/posts/' . $id,
			userId: 'alice',
		))->status);
		self::assertSame(200, $this->app->handle(new Request(
			'GET',
			'/api/mastodon/v1/statuses/' . $id,
			userId: 'alice',
		))->status);
		self::assertSame(404, $this->app->handle(new Request(
			'GET',
			'/api/v1/posts/' . $id,
			userId: 'carol',
		))->status);
		self::assertSame(404, $this->app->handle(new Request(
			'GET',
			'/api/v1/posts/' . $id,
		))->status);

		$unfollow = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/accounts/bob/unfollow',
			userId: 'alice',
			scopes: ['write:follows'],
		));
		self::assertSame(200, $unfollow->status);
		self::assertSame(404, $this->app->handle(new Request(
			'GET',
			'/api/v1/posts/' . $id,
			userId: 'alice',
		))->status);
	}

	public function testAnUnlistedPostIsReadableByAnyone(): void
	{
		$id = $this->post('quietly public', 'unlisted')->decodedBody()['post']['id'];

		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);
	}

	public function testAPublicPostIsReadableByAnyone(): void
	{
		$id = $this->post('for everyone', 'public')->decodedBody()['post']['id'];

		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);
	}

	public function testADirectPostIsReadableByItsAuthorAndRecipientsOnly(): void
	{
		$response = $this->post('just for bob', 'direct', recipientIds: ['bob']);
		self::assertSame(201, $response->status);
		$body = $response->decodedBody()['post'];
		$id = $body['id'];
		self::assertSame(['bob'], $body['recipient_ids']);

		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'alice'))->status);
		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'bob'))->status);
		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'carol'))->status);
		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);

		self::assertCount(0, $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);
		$bobHome = $this->app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'bob'))->decodedBody()['posts'];
		self::assertSame([$id], array_column($bobHome, 'id'));
		$carolHome = $this->app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'carol'))->decodedBody()['posts'];
		self::assertSame([], $carolHome);
	}

	public function testDirectTimelineContainsOnlyTheSignedInAccountsMessages(): void
	{
		$sent = $this->post('alice to bob', 'direct', recipientIds: ['bob'])->decodedBody()['post'];
		$received = $this->post('bob to alice', 'direct', authorId: 'bob', recipientIds: ['alice'])->decodedBody()['post'];
		$elsewhere = $this->post('bob to carol', 'direct', authorId: 'bob', recipientIds: ['carol'])->decodedBody()['post'];
		$this->post('public post', 'public', authorId: 'bob');

		self::assertSame(401, $this->app->handle(new Request('GET', '/api/v1/timelines/direct'))->status);
		$alice = $this->app->handle(new Request('GET', '/api/v1/timelines/direct', userId: 'alice'));
		self::assertSame(200, $alice->status);
		$expected = [$received['id'], $sent['id']];
		usort($expected, static fn (string $left, string $right): int => strcmp($right, $left));
		self::assertSame($expected, array_column($alice->decodedBody()['posts'], 'id'));
		$sentPost = array_values(array_filter($alice->decodedBody()['posts'], static fn (array $post): bool => $post['id'] === $sent['id']))[0];
		self::assertSame(['bob'], $sentPost['recipient_ids']);
		self::assertFalse($alice->decodedBody()['has_more']);

		$carol = $this->app->handle(new Request('GET', '/api/v1/timelines/direct', userId: 'carol'));
		self::assertSame([$elsewhere['id']], array_column($carol->decodedBody()['posts'], 'id'));
	}

	public function testDirectRecipientsMustBeKnownLocalAccounts(): void
	{
		self::assertSame(422, $this->post('nobody', 'direct', recipientIds: ['unknown'])->status);
		self::assertSame(422, $this->post('self', 'direct', recipientIds: ['alice'])->status);
		self::assertSame(422, $this->post('remote', 'direct', recipientIds: ['https://remote.example/users/bob'])->status);
		self::assertSame(422, $this->post('public with audience', 'public', recipientIds: ['bob'])->status);
	}

	public function testAReplyCarriesItsTargetAndTheParentAuthorsAudience(): void
	{
		$parent = $this->post('parent', 'public', authorId: 'bob')->decodedBody()['post'];

		$reply = $this->post(
			'reply',
			'public',
			authorId: 'alice',
			inReplyToId: $parent['id'],
		);
		self::assertSame(201, $reply->status);
		self::assertSame($parent['id'], $reply->decodedBody()['post']['in_reply_to_id']);

		$directParent = $this->post('direct parent', 'direct', authorId: 'bob', recipientIds: ['carol'])->decodedBody()['post'];
		$directReply = $this->post(
			'direct reply',
			'direct',
			authorId: 'carol',
			inReplyToId: $directParent['id'],
		);
		self::assertSame(201, $directReply->status);
		// The parent author is added automatically; the parent's other
		// recipient is the reply author and is not repeated.
		self::assertSame(['bob'], $directReply->decodedBody()['post']['recipient_ids']);

		// Alice is not a participant of the direct parent, so she may not
		// reply to it or widen it to the public timeline.
		$leak = $this->post(
			'leaked reply',
			'public',
			authorId: 'alice',
			inReplyToId: $directParent['id'],
		);
		self::assertSame(422, $leak->status);
	}

	public function testAReplyCannotWidenTheParentsAudience(): void
	{
		$private = $this->post('followers', 'followers', authorId: 'bob')->decodedBody()['post'];

		self::assertSame(422, $this->post(
			'public reply',
			'public',
			authorId: 'alice',
			inReplyToId: $private['id'],
		)->status);

		$missing = $this->post('missing parent', 'public', authorId: 'alice', inReplyToId: 'does-not-exist');
		self::assertSame(422, $missing->status);

		$blank = $this->post('blank parent', 'public', authorId: 'alice', inReplyToId: '   ');
		self::assertSame(201, $blank->status);
		self::assertNull($blank->decodedBody()['post']['in_reply_to_id']);
	}

	private function post(
		string $content,
		string $visibility,
		string $authorId = 'alice',
		array $recipientIds = [],
		?string $inReplyToId = null,
	): Response {
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => $content,
				'visibility' => $visibility,
				'recipient_ids' => $recipientIds,
				'in_reply_to_id' => $inReplyToId,
			], JSON_THROW_ON_ERROR),
			userId: $authorId,
		));
	}
}
