<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Http\RouteManifest;
use AlohaSocial\Core\Http\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
	private Router $router;

	protected function setUp(): void
	{
		$this->router = new Router(new RouteManifest(
			new Route('posts.show', 'GET', '/api/v1/posts/{id}', HealthHandler::class, false),
			new Route('posts.delete', 'DELETE', '/api/v1/posts/{id}', HealthHandler::class),
			new Route('posts.featured', 'GET', '/api/v1/posts/featured', HealthHandler::class, false),
			new Route('files.show', 'GET', '/api/v1/files/{path}', HealthHandler::class, false),
		));
	}

	public function testMatchesAStaticRoute(): void
	{
		$match = $this->router->match('GET', '/api/v1/posts/featured');

		self::assertSame('posts.featured', $match->route->name);
		self::assertSame([], $match->parameters);
	}

	public function testAStaticRouteWinsOverAParameterisedOne(): void
	{
		// The manifest declares {id} first; the router must still prefer the
		// exact route rather than swallowing "featured" as an id.
		$match = $this->router->match('GET', '/api/v1/posts/featured');

		self::assertSame('/api/v1/posts/featured', $match->route->path);
	}

	public function testExtractsPathParameters(): void
	{
		$match = $this->router->match('GET', '/api/v1/posts/abc123');

		self::assertSame('posts.show', $match->route->name);
		self::assertSame(['id' => 'abc123'], $match->parameters);
	}

	public function testDecodesAParameter(): void
	{
		$match = $this->router->match('GET', '/api/v1/posts/a%20b');

		self::assertSame(['id' => 'a b'], $match->parameters);
	}

	public function testMatchesASingleSegmentParameter(): void
	{
		$match = $this->router->match('GET', '/api/v1/files/avatar.png');

		self::assertSame('files.show', $match->route->name);
		self::assertSame(['path' => 'avatar.png'], $match->parameters);
	}

	public function testAParameterDoesNotSpanSegments(): void
	{
		// A parameter is one segment. Allowing a slash would let "a/b" reach
		// a handler that expects one file name.
		$this->expectException(HttpException::class);

		$this->router->match('GET', '/api/v1/files/a/b');
	}

	public function testToleratesATrailingSlash(): void
	{
		$match = $this->router->match('GET', '/api/v1/posts/featured/');

		self::assertSame('posts.featured', $match->route->name);
	}

	public function testUnknownPathIs404(): void
	{
		try {
			$this->router->match('GET', '/api/v1/nothing');
			self::fail('Expected a 404.');
		} catch (HttpException $exception) {
			self::assertSame(404, $exception->status);
			self::assertSame('not_found', $exception->errorCode);
		}
	}

	public function testKnownPathWithAnotherMethodIs405(): void
	{
		try {
			$this->router->match('PUT', '/api/v1/posts/abc');
			self::fail('Expected a 405.');
		} catch (HttpException $exception) {
			self::assertSame(405, $exception->status);
			self::assertSame('DELETE, GET', $exception->headers['Allow']);
		}
	}

	public function testRefusesARouteThatRepeatsAParameter(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Router(new RouteManifest(
			new Route('broken', 'GET', '/api/v1/{id}/posts/{id}', HealthHandler::class, false),
		));
	}

	public function testExposesTheManifest(): void
	{
		self::assertCount(4, $this->router->manifest()->routes());
	}
}
