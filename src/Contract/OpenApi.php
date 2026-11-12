<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Contract;

use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Http\RouteManifest;
use JsonException;

/**
 * The route manifest as an OpenAPI 3.1 document.
 *
 * This is deliberately a manifest-level skeleton: paths, methods, operation
 * ids, auth, rate limits and path parameters are exact. Request and response
 * bodies are not generated because the route manifest does not declare them;
 * inventing schemas here would make the export look more authoritative than
 * the contract is. See docs/reference/routes.md for the payload details.
 */
final class OpenApi
{
	public const VERSION = '3.1.0';

	public const TITLE = 'Aloha Social Core API';

	public const API_VERSION = '1.0.0';

	/**
	 * @return array<string, mixed>
	 */
	public static function document(RouteManifest $manifest): array
	{
		$paths = [];

		foreach ($manifest->routes() as $route) {
			$operation = [
				'operationId' => self::operationId($route),
				'summary' => $route->summary ?? $route->name,
				'tags' => [self::tag($route->path)],
				'responses' => [
					'default' => [
						'description' => 'Handler-defined response; see docs/reference/routes.md.',
					],
				],
			];

			$parameters = self::pathParameters($route->path);
			if ($parameters !== []) {
				$operation['parameters'] = $parameters;
			}

			if ($route->requiresAuth) {
				$operation['security'] = [['bearerAuth' => []]];
			}

			if ($route->rateLimit !== null) {
				$operation['x-rate-limit'] = $route->rateLimit;
			}

			$path = $paths[$route->path] ?? [];
			$path[strtolower($route->method)] = $operation;
			$paths[$route->path] = $path;
		}

		ksort($paths);

		return [
			'openapi' => self::VERSION,
			'info' => [
				'title' => self::TITLE,
				'version' => self::API_VERSION,
				'description' => 'Generated from Contract\\Routes; payload details live in docs/reference/routes.md.',
			],
			'paths' => $paths,
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => [
						'type' => 'http',
						'scheme' => 'bearer',
					],
				],
			],
		];
	}

	/**
	 * @throws JsonException
	 */
	public static function toJson(RouteManifest $manifest): string
	{
		return json_encode(
			self::document($manifest),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);
	}

	private static function operationId(Route $route): string
	{
		return str_replace(['.', '-'], '_', $route->name);
	}

	private static function tag(string $path): string
	{
		$prefix = '/api/v1/';
		if (str_starts_with($path, $prefix)) {
			$segment = explode('/', substr($path, strlen($prefix)))[0];

			return $segment === '' ? 'api' : $segment;
		}

		return match (true) {
			$path === '/healthz' => 'health',
			$path === '/inbox', $path === '/inbox/shared' => 'inbox',
			default => 'federation',
		};
	}

	/**
	 * @return list<array{name: string, in: string, required: bool, schema: array{type: string}}>
	 */
	private static function pathParameters(string $path): array
	{
		preg_match_all('/\{([^}]+)\}/', $path, $matches);

		$parameters = [];
		foreach (array_unique($matches[1]) as $name) {
			$parameters[] = [
				'name' => $name,
				'in' => 'path',
				'required' => true,
				'schema' => ['type' => 'string'],
			];
		}

		return $parameters;
	}
}
