<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
	/**
	 * @var array<string, mixed>
	 */
	private array $server;

	protected function setUp(): void
	{
		$this->server = $_SERVER;
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->server;
	}

	public function testNormalisesMethodAndPath(): void
	{
		$request = new Request('post', 'api/v1/posts/');

		self::assertSame('POST', $request->method);
		self::assertSame('/api/v1/posts', $request->path);
	}

	public function testKeepsTheRootPath(): void
	{
		self::assertSame('/', (new Request('GET', '/'))->path);
	}

	public function testStripsTheQueryStringFromThePath(): void
	{
		self::assertSame('/healthz', (new Request('GET', '/healthz?limit=5'))->path);
	}

	public function testNormalisesHeaderNames(): void
	{
		$request = new Request('GET', '/', headers: ['Content-Type' => 'application/json']);

		self::assertSame('application/json', $request->header('content-type'));
		self::assertSame('application/json', $request->header('CONTENT-TYPE'));
		self::assertNull($request->header('accept'));
	}

	public function testJoinsRepeatedHeaders(): void
	{
		$request = new Request('GET', '/', headers: ['Accept' => ['text/html', 'application/json']]);

		self::assertSame('text/html, application/json', $request->header('accept'));
	}

	public function testDecodesAJsonBody(): void
	{
		$request = new Request('POST', '/', body: '{"content":"hello","n":2}');

		self::assertSame(['content' => 'hello', 'n' => 2], $request->json());
	}

	public function testAnEmptyBodyIsAnEmptyObject(): void
	{
		self::assertSame([], (new Request('POST', '/'))->json());
	}

	#[DataProvider('invalidBodies')]
	public function testAnInvalidBodyIsABadRequest(string $body): void
	{
		try {
			(new Request('POST', '/', body: $body))->json();
			self::fail('Expected a 400.');
		} catch (HttpException $exception) {
			self::assertSame(400, $exception->status);
			self::assertSame('invalid_json', $exception->errorCode);
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidBodies(): iterable
	{
		yield 'broken' => ['{"content":'];
		yield 'scalar' => ['"just a string"'];
		yield 'number' => ['42'];
		yield 'null' => ['null'];
	}

	#[DataProvider('integerQueries')]
	public function testQueryIntUsesTheDefaultOutsideTheRange(?string $value, int $expected): void
	{
		$query = $value === null ? [] : ['limit' => $value];

		self::assertSame($expected, (new Request('GET', '/', $query))->queryInt('limit', 20, 1, 40));
	}

	/**
	 * @return iterable<string, array{?string, int}>
	 */
	public static function integerQueries(): iterable
	{
		yield 'missing' => [null, 20];
		yield 'valid' => ['7', 7];
		yield 'zero' => ['0', 20];
		yield 'too high' => ['41', 20];
		yield 'text' => ['many', 20];
		yield 'float' => ['1.5', 20];
	}

	public function testQueryParamIgnoresArrays(): void
	{
		$request = new Request('GET', '/', ['tag' => ['a', 'b']]);

		self::assertNull($request->queryParam('tag'));
		self::assertSame('fallback', $request->queryParam('missing', 'fallback'));
	}

	public function testWithAttributeReturnsANewRequest(): void
	{
		$original = new Request('GET', '/');
		$changed = $original->withAttribute('route', 'x');

		self::assertNull($original->attribute('route'));
		self::assertSame('x', $changed->attribute('route'));
	}

	public function testWithUserIdReturnsANewRequest(): void
	{
		$original = new Request('GET', '/');
		$authenticated = $original->withUserId('alice');

		self::assertNull($original->userId);
		self::assertSame('alice', $authenticated->userId);
	}

	public function testBuildsFromGlobals(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'get';
		$_SERVER['REQUEST_URI'] = '/healthz?limit=5';
		$_SERVER['HTTP_X_TEST'] = 'yes';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$request = Request::fromGlobals();

		self::assertSame('GET', $request->method);
		self::assertSame('/healthz', $request->path);
		self::assertSame('5', $request->queryParam('limit'));
		self::assertSame('yes', $request->header('x-test'));
		self::assertSame('203.0.113.9', $request->remoteAddress);
		self::assertSame('', $request->body);
	}
}
