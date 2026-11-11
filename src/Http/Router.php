<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use InvalidArgumentException;

/**
 * Matches a request against the manifest.
 *
 * Routes without parameters win over routes with parameters, so
 * `/api/v1/posts/featured` can never be swallowed by `/api/v1/posts/{id}`.
 * Path segments are decoded after matching, never before: a `%2F` in a
 * segment must not create a new segment.
 */
final class Router
{
	/**
	 * @var array<string, list<array{route: Route, pattern: string, parameters: list<string>, order: int}>>
	 */
	private array $compiled = [];

	public function __construct(
		private readonly RouteManifest $manifest,
	) {
		$this->compile();
	}

	public function match(string $method, string $path): RouteMatch
	{
		$method = strtoupper($method);
		$path = self::normalizePath($path);

		$allowed = [];

		foreach ($this->compiled as $routeMethod => $routes) {
			foreach ($routes as $entry) {
				if (preg_match($entry['pattern'], $path, $matches) !== 1) {
					continue;
				}

				if ($routeMethod !== $method) {
					$allowed[] = $routeMethod;

					continue;
				}

				$parameters = [];
				foreach ($entry['parameters'] as $name) {
					$parameters[$name] = rawurldecode((string) $matches[$name]);
				}

				return new RouteMatch($entry['route'], $parameters);
			}
		}

		if ($allowed !== []) {
			throw HttpException::methodNotAllowed($allowed);
		}

		throw HttpException::notFound('No route matches ' . $method . ' ' . $path . '.');
	}

	public function manifest(): RouteManifest
	{
		return $this->manifest;
	}

	private function compile(): void
	{
		$order = 0;

		foreach ($this->manifest->routes() as $route) {
			$parameters = [];
			$pattern = '';
			$offset = 0;

			preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $route->path, $placeholders, PREG_OFFSET_CAPTURE);
			foreach ($placeholders[0] as $index => [$placeholder, $position]) {
				$name = $placeholders[1][$index][0];

				if (in_array($name, $parameters, true)) {
					throw new InvalidArgumentException(
						'Route ' . $route->name . ' uses the parameter {' . $name . '} twice.',
					);
				}

				$parameters[] = $name;

				$pattern .= preg_quote(substr($route->path, $offset, $position - $offset), '#');
				$pattern .= '(?P<' . $name . '>[^/]+)';
				$offset = $position + strlen($placeholder);
			}

			$pattern .= preg_quote(substr($route->path, $offset), '#');

			$this->compiled[$route->method][] = [
				'route' => $route,
				'pattern' => '#^' . $pattern . '$#',
				'parameters' => $parameters,
				'order' => $order++,
			];
		}

		// Fewest parameters first, then declaration order: the static route
		// always gets the chance to match before the catch-all one.
		foreach ($this->compiled as $method => $routes) {
			usort($routes, static function (array $a, array $b): int {
				$byParameters = count($a['parameters']) <=> count($b['parameters']);

				return $byParameters !== 0 ? $byParameters : $a['order'] <=> $b['order'];
			});

			$this->compiled[$method] = $routes;
		}
	}

	private static function normalizePath(string $path): string
	{
		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}

		if ($path !== '/') {
			$path = rtrim($path, '/');
		}

		return $path === '' ? '/' : $path;
	}
}
