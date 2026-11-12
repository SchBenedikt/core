<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Contract;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\RouteManifest;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
	public function testDeclaresTheLocalApi(): void
	{
		$manifest = Routes::manifest();

		self::assertCount(16, $manifest->routes());
		self::assertNotNull($manifest->find('posts.create'));
		self::assertNotNull($manifest->find('timelines.home'));
		self::assertNotNull($manifest->find('inbox'));
		self::assertNotNull($manifest->find('actors.get'));
		self::assertNotNull($manifest->find('actors.followers'));
		self::assertNotNull($manifest->find('actors.following'));
		self::assertNotNull($manifest->find('actors.outbox'));
		self::assertNotNull($manifest->find('webfinger'));
	}

	public function testEveryHandlerExistsAndImplementsTheContract(): void
	{
		foreach (Routes::manifest()->routes() as $route) {
			self::assertTrue(
				class_exists($route->handler),
				'The handler ' . $route->handler . ' of route ' . $route->name . ' does not exist.',
			);
			self::assertTrue(
				is_subclass_of($route->handler, Handler::class),
				'The handler ' . $route->handler . ' does not implement ' . Handler::class . '.',
			);
		}
	}

	public function testEveryApiRouteIsVersioned(): void
	{
		foreach (Routes::manifest()->routes() as $route) {
			// The liveness probe and the ActivityPub inbox are protocol
			// endpoints, not part of the versioned client API.
			if (in_array($route->name, ['health', 'inbox', 'actors.get', 'actors.followers', 'actors.following', 'actors.outbox', 'webfinger'], true)) {
				continue;
			}

			self::assertStringStartsWith(ApiVersion::BASE_PATH . '/', $route->path);
		}
	}

	public function testTheWriteRouteIsProtectedAndRateLimited(): void
	{
		$route = Routes::manifest()->find('posts.create');

		self::assertNotNull($route);
		self::assertTrue($route->requiresAuth);
		self::assertSame('30/900', $route->rateLimit);
	}

	public function testTheTimelineAndPostRoutesArePubliclyReadable(): void
	{
		foreach (['posts.get', 'timelines.public', 'instance.get', 'health'] as $name) {
			$route = Routes::manifest()->find($name);

			self::assertNotNull($route);
			self::assertFalse($route->requiresAuth, 'Route ' . $name . ' must be public.');
		}
	}

	public function testTheInboxIsPublicButRateLimited(): void
	{
		$route = Routes::manifest()->find('inbox');

		self::assertNotNull($route);
		self::assertFalse($route->requiresAuth, 'The inbox authenticates by signature, not by session.');
		self::assertSame('120/60', $route->rateLimit);
		self::assertSame('/inbox', $route->path);
	}

	public function testTheActorRoutesArePublic(): void
	{
		foreach (['actors.get', 'actors.followers', 'actors.following', 'actors.outbox'] as $name) {
			$route = Routes::manifest()->find($name);

			self::assertNotNull($route);
			self::assertFalse($route->requiresAuth, 'Actor documents are public.');
			self::assertSame('120/60', $route->rateLimit);
		}

		self::assertSame('/@{handle}', Routes::manifest()->find('actors.get')?->path);
		self::assertSame('/@{handle}/followers', Routes::manifest()->find('actors.followers')?->path);
		self::assertSame('/@{handle}/following', Routes::manifest()->find('actors.following')?->path);
		self::assertSame('/@{handle}/outbox', Routes::manifest()->find('actors.outbox')?->path);
	}

	public function testWebFingerIsPublicAndRateLimited(): void
	{
		$route = Routes::manifest()->find('webfinger');

		self::assertNotNull($route);
		self::assertFalse($route->requiresAuth, 'Handle resolution is public.');
		self::assertSame('120/60', $route->rateLimit);
		self::assertSame('/.well-known/webfinger', $route->path);
	}

	public function testTheManifestExportsAStableJsonDocument(): void
	{
		$decoded = json_decode(Routes::manifest()->toJson(), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame(RouteManifest::VERSION, $decoded['version']);
		self::assertSame('alohasocial/core', $decoded['generated_by']);
		self::assertCount(16, $decoded['routes']);
		self::assertSame('/api/v1/posts', $decoded['routes'][2]['path']);
	}

	public function testNoRouteIsMissingAHandlerAtBoot(): void
	{
		// Application::inMemory() refuses to boot when the manifest names a
		// handler nobody registered; this test is the regression guard for it.
		$app = Application::inMemory();

		self::assertCount(16, $app->routes->routes());
	}
}
