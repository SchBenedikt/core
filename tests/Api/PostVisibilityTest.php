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
		);
	}

	public function testAFollowersOnlyPostIsNotReadableByOthers(): void
	{
		$id = $this->post('just for followers', 'followers')->decodedBody()['post']['id'];

		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);
		self::assertSame(404, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'bob'))->status);
		self::assertSame(200, $this->app->handle(new Request('GET', '/api/v1/posts/' . $id, userId: 'alice'))->status);
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

	private function post(string $content, string $visibility): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content, 'visibility' => $visibility], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}
}
