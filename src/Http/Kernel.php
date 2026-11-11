<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The front controller of the core: route, middleware, handler, response.
 *
 * The same kernel runs on both hosts. It turns a matched route into a request
 * with `route` and path parameters as attributes, runs the middleware chain,
 * and renders any error in the documented shape. Unexpected failures are
 * logged and answered with a 500 that does not leak internals unless the
 * kernel was built in debug mode.
 */
final class Kernel
{
	/**
	 * @param list<Middleware> $middleware outermost first
	 */
	public function __construct(
		private readonly Router $router,
		private readonly HandlerResolver $resolver,
		private readonly array $middleware = [],
		private readonly ?LoggerInterface $logger = null,
		private readonly bool $debug = false,
	) {
	}

	public function handle(Request $request): Response
	{
		try {
			$match = $this->router->match($request->method, $request->path);
			$request = $this->bind($request, $match);

			$handler = $this->resolver->resolve($match->route->handler);

			return $this->pipeline($handler)($request);
		} catch (HttpException $exception) {
			return $exception->toResponse();
		} catch (Throwable $exception) {
			$this->logger?->error(
				'Unhandled error while handling {method} {path}: {message}',
				[
					'method' => $request->method,
					'path' => $request->path,
					'message' => $exception->getMessage(),
					'exception' => $exception,
				],
			);

			return Response::json(
				[
					'error' => [
						'code' => 'internal_error',
						'message' => $this->debug
							? $exception->getMessage()
							: 'Internal server error.',
					],
				],
				500,
			);
		}
	}

	private function bind(Request $request, RouteMatch $match): Request
	{
		$request = $request->withAttribute('route', $match->route);

		foreach ($match->parameters as $name => $value) {
			$request = $request->withAttribute($name, $value);
		}

		return $request;
	}

	/**
	 * @return callable(Request): Response
	 */
	private function pipeline(Handler $handler): callable
	{
		$next = static fn (Request $request): Response => $handler->handle($request);

		foreach (array_reverse($this->middleware) as $middleware) {
			$next = static fn (Request $request): Response => $middleware->process($request, $next);
		}

		return $next;
	}
}
