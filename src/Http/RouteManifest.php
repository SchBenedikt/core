<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use JsonException;

/**
 * The single source of truth for the HTTP surface.
 *
 * Both hosts validate their own routing against this list, and the export in
 * `bin/export-routes` is what tooling and tests compare. See ADR 0003.
 */
final readonly class RouteManifest
{
	public const VERSION = 1;

	/**
	 * @var list<Route>
	 */
	private array $routes;

	public function __construct(Route ...$routes)
	{
		$names = [];
		$paths = [];
		foreach ($routes as $route) {
			if (isset($names[$route->name])) {
				throw new \InvalidArgumentException('Duplicate route name: ' . $route->name);
			}
			$names[$route->name] = true;

			$key = $route->method . ' ' . $route->path;
			if (isset($paths[$key])) {
				throw new \InvalidArgumentException('Duplicate route: ' . $key);
			}
			$paths[$key] = true;
		}

		$this->routes = array_values($routes);
	}

	/**
	 * @return list<Route>
	 */
	public function routes(): array
	{
		return $this->routes;
	}

	public function find(string $name): ?Route
	{
		foreach ($this->routes as $route) {
			if ($route->name === $name) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'version' => self::VERSION,
			'generated_by' => 'alohasocial/core',
			'routes' => array_map(static fn (Route $route): array => $route->toArray(), $this->routes),
		];
	}

	/**
	 * @throws JsonException
	 */
	public function toJson(): string
	{
		return json_encode(
			$this->toArray(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);
	}
}
