<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Contract;

use AlohaSocial\Core\Contract\OpenApi;
use AlohaSocial\Core\Contract\Routes;
use PHPUnit\Framework\TestCase;

final class OpenApiTest extends TestCase
{
	public function testEveryRouteBecomesOneOperation(): void
	{
		$manifest = Routes::manifest();
		$document = OpenApi::document($manifest);

		self::assertSame('3.1.0', $document['openapi']);
		self::assertSame(OpenApi::TITLE, $document['info']['title']);

		$operations = 0;
		$operationIds = [];
		foreach ($document['paths'] as $path) {
			foreach (['get', 'post', 'delete', 'put', 'patch'] as $method) {
				if (!isset($path[$method])) {
					continue;
				}

				$operations++;
				$operationIds[] = $path[$method]['operationId'];
			}
		}

		self::assertCount(count($manifest->routes()), array_unique($operationIds));
		self::assertSame(count($manifest->routes()), $operations);
	}

	public function testAuthAndPathParametersAreCarriedOver(): void
	{
		$document = OpenApi::document(Routes::manifest());

		$create = $document['paths']['/api/v1/posts']['post'];
		self::assertSame('posts_create', $create['operationId']);
		self::assertSame([['bearerAuth' => []]], $create['security']);
		self::assertSame('30/900', $create['x-rate-limit']);

		$atproto = $document['paths']['/api/v1/posts/{id}/atproto']['post'];
		self::assertSame('atproto_publish', $atproto['operationId']);
		self::assertSame([['bearerAuth' => []]], $atproto['security']);
		self::assertSame('10/900', $atproto['x-rate-limit']);

		$health = $document['paths']['/healthz']['get'];
		self::assertArrayNotHasKey('security', $health);

		$mastodon = $document['paths']['/api/mastodon/v1/statuses']['post'];
		self::assertSame('mastodon', $mastodon['tags'][0]);
		self::assertSame('mastodon_statuses_create', $mastodon['operationId']);
		self::assertSame([['bearerAuth' => []]], $mastodon['security']);
		self::assertSame('30/900', $mastodon['x-rate-limit']);
		self::assertSame(['write:statuses'], $mastodon['x-required-scopes']);

		$post = $document['paths']['/api/v1/posts/{id}']['get'];
		self::assertSame(
			[['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
			$post['parameters'],
		);
	}

	public function testTheJsonExportIsValidAndStable(): void
	{
		$decoded = json_decode(OpenApi::toJson(Routes::manifest()), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('3.1.0', $decoded['openapi']);
		self::assertArrayHasKey('/inbox', $decoded['paths']);
		self::assertArrayHasKey('post', $decoded['paths']['/inbox']);
		self::assertArrayHasKey('/inbox/shared', $decoded['paths']);
		self::assertArrayHasKey('post', $decoded['paths']['/inbox/shared']);
		self::assertSame('inbox', $decoded['paths']['/inbox/shared']['post']['tags'][0]);
		self::assertArrayHasKey('/api/v1/media', $decoded['paths']);
		self::assertArrayHasKey('post', $decoded['paths']['/api/v1/media']);
		self::assertArrayHasKey('/api/v1/media/{id}', $decoded['paths']);
		self::assertArrayHasKey('/api/v1/remote-posts/{id}/thread', $decoded['paths']);
		self::assertSame(
			[['bearerAuth' => []]],
			$decoded['paths']['/api/v1/remote-posts/{id}/thread']['get']['security'],
		);
	}
}
