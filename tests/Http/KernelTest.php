<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HandlerMap;
use AlohaSocial\Core\Http\Kernel;
use AlohaSocial\Core\Http\Middleware;
use AlohaSocial\Core\Http\Middleware\RequireAuthentication;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Http\RouteManifest;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Tests\Support\ArrayPsrLogger;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Handlers and middleware used only by this test file.
 */
final class OkHandler implements Handler
{
	public function handle(Request $request): Response
	{
		return Response::json(['ok' => true]);
	}
}

final class EchoUserHandler implements Handler
{
	public function handle(Request $request): Response
	{
		return Response::json(['user' => $request->userId]);
	}
}

final class BoomHandler implements Handler
{
	public function handle(Request $request): Response
	{
		throw new RuntimeException('kaboom');
	}
}

final class MarkerMiddleware implements Middleware
{
	public function __construct(
		private readonly string $header,
	) {
	}

	public function process(Request $request, callable $next): Response
	{
		return $next($request)->withHeader($this->header, 'yes');
	}
}

final class BlockingMiddleware implements Middleware
{
	public function process(Request $request, callable $next): Response
	{
		return Response::json(['error' => ['code' => 'blocked']], 403);
	}
}

final class KernelTest extends TestCase
{
	public function testServesAPublicRoute(): void
	{
		$response = $this->kernel()->handle(new Request('GET', '/ok'));

		self::assertSame(200, $response->status);
		self::assertSame(['ok' => true], $response->decodedBody());
	}

	public function testRefusesAnAnonymousRequestToAProtectedRoute(): void
	{
		$response = $this->kernel()->handle(new Request('GET', '/secret'));

		self::assertSame(401, $response->status);
		self::assertSame('Bearer', $response->header('WWW-Authenticate'));
		self::assertSame('authentication_required', $response->decodedBody()['error']['code']);
	}

	public function testAllowsAnAuthenticatedRequest(): void
	{
		$response = $this->kernel()->handle(new Request('GET', '/secret', userId: 'alice'));

		self::assertSame(200, $response->status);
		self::assertSame('alice', $response->decodedBody()['user']);
	}

	public function testUnknownRouteIsAJson404(): void
	{
		$response = $this->kernel()->handle(new Request('GET', '/nowhere'));

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
		self::assertSame('application/json; charset=utf-8', $response->header('content-type'));
	}

	public function testWrongMethodAnswers405WithAllow(): void
	{
		$response = $this->kernel()->handle(new Request('POST', '/ok'));

		self::assertSame(405, $response->status);
		self::assertSame('GET', $response->header('Allow'));
	}

	public function testAnUnexpectedErrorIsA500ThatHidesTheReason(): void
	{
		$logger = new ArrayPsrLogger();

		$response = $this->kernel(logger: $logger)->handle(new Request('GET', '/boom'));

		self::assertSame(500, $response->status);
		self::assertSame('internal_error', $response->decodedBody()['error']['code']);
		self::assertSame('Internal server error.', $response->decodedBody()['error']['message']);
		self::assertSame(['error'], $logger->levels());
	}

	public function testDebugModeExposesTheReason(): void
	{
		$response = $this->kernel(debug: true)->handle(new Request('GET', '/boom'));

		self::assertSame(500, $response->status);
		self::assertSame('kaboom', $response->decodedBody()['error']['message']);
	}

	public function testAnUnregisteredHandlerIsA500NotACrash(): void
	{
		$response = $this->kernel()->handle(new Request('GET', '/missing'));

		self::assertSame(500, $response->status);
	}

	public function testMiddlewareRunsInOrder(): void
	{
		$response = $this->kernel(middleware: [
			new MarkerMiddleware('X-Outer'),
			new MarkerMiddleware('X-Inner'),
		])->handle(new Request('GET', '/ok'));

		self::assertSame('yes', $response->header('x-outer'));
		self::assertSame('yes', $response->header('x-inner'));
	}

	public function testMiddlewareCanShortCircuit(): void
	{
		$response = $this->kernel(middleware: [new BlockingMiddleware()])
			->handle(new Request('GET', '/secret', userId: 'alice'));

		self::assertSame(403, $response->status);
		self::assertSame('blocked', $response->decodedBody()['error']['code']);
	}

	private function kernel(
		array $middleware = [new RequireAuthentication()],
		bool $debug = false,
		?ArrayPsrLogger $logger = null,
	): Kernel {
		$manifest = new RouteManifest(
			new Route('ok', 'GET', '/ok', OkHandler::class, false),
			new Route('secret', 'GET', '/secret', EchoUserHandler::class),
			new Route('boom', 'GET', '/boom', BoomHandler::class, false),
			new Route('missing', 'GET', '/missing', InstanceHandler::class, false),
		);

		$handlers = (new HandlerMap())
			->with(OkHandler::class, static fn (): OkHandler => new OkHandler())
			->with(EchoUserHandler::class, static fn (): EchoUserHandler => new EchoUserHandler())
			->with(BoomHandler::class, static fn (): BoomHandler => new BoomHandler());

		return new Kernel(
			new Router($manifest),
			$handlers,
			$middleware,
			$logger,
			$debug,
		);
	}
}
