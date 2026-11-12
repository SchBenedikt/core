<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The local write side of per-user blocks: create, list, repeat and remove.
 *
 * A block is private to the caller. The test also proves that one account's
 * block cannot be read or removed by another account.
 */
final class BlockApiTest extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testBlockIsIdempotentListedAndRemoved(): void
	{
		$app = $this->app();

		$created = $this->block($app);

		self::assertSame(201, $created->status);
		$block = $created->decodedBody()['block'];
		self::assertSame(self::BOB, $block['actor']);
		self::assertSame('2026-11-11T10:00:00Z', $block['blocked_at']);
		self::assertSame('/api/v1/blocks/' . $block['id'], $created->header('Location'));

		$repeated = $this->block($app);
		self::assertSame(200, $repeated->status);
		self::assertSame($block['id'], $repeated->decodedBody()['block']['id']);

		$list = $app->handle(new Request('GET', '/api/v1/blocks', userId: 'alice'));
		self::assertSame(200, $list->status);
		self::assertSame(1, $list->decodedBody()['total']);
		self::assertSame([$block['id']], array_column($list->decodedBody()['blocks'], 'id'));

		$other = $app->handle(new Request('GET', '/api/v1/blocks', userId: 'bob'));
		self::assertSame(0, $other->decodedBody()['total']);

		self::assertSame(
			404,
			$app->handle(new Request('DELETE', '/api/v1/blocks/' . $block['id'], userId: 'bob'))->status,
		);
		self::assertSame(1, $app->handle(new Request('GET', '/api/v1/blocks', userId: 'alice'))->decodedBody()['total']);

		$removed = $app->handle(new Request('DELETE', '/api/v1/blocks/' . $block['id'], userId: 'alice'));
		self::assertSame(204, $removed->status);
		self::assertSame(0, $app->handle(new Request('GET', '/api/v1/blocks', userId: 'alice'))->decodedBody()['total']);
		self::assertSame(
			404,
			$app->handle(new Request('DELETE', '/api/v1/blocks/' . $block['id'], userId: 'alice'))->status,
		);
	}

	public function testBlockRejectsAnInvalidActorAndRequiresAuthentication(): void
	{
		$app = $this->app();

		self::assertSame(
			401,
			$app->handle(new Request(
				method: 'POST',
				path: '/api/v1/blocks',
				headers: ['Content-Type' => 'application/json'],
				body: json_encode(['actor' => self::BOB], JSON_THROW_ON_ERROR),
			))->status,
		);

		$invalid = $this->block($app, 'bob');

		self::assertSame(422, $invalid->status);
		self::assertSame('validation_failed', $invalid->decodedBody()['error']['code']);
		self::assertArrayHasKey('actor', $invalid->decodedBody()['error']['fields']);
		self::assertSame(0, $app->handle(new Request('GET', '/api/v1/blocks', userId: 'alice'))->decodedBody()['total']);
	}

	public function testTheBlockListRejectsAnInvalidCursor(): void
	{
		$response = $this->app()->handle(new Request(
			'GET',
			'/api/v1/blocks',
			['before' => 'yesterday'],
			userId: 'alice',
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_cursor', $response->decodedBody()['error']['code']);
	}

	private function app(): Application
	{
		return Application::inMemory(clock: $this->clock);
	}

	private function block(Application $app, string $actor = self::BOB, string $userId = 'alice'): Response
	{
		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => $actor], JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}
}
