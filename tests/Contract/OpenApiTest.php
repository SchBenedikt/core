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

		$instance = $document['paths']['/api/mastodon/v1/instance']['get'];
		self::assertSame('mastodon_instance', $instance['operationId']);
		self::assertArrayNotHasKey('security', $instance);
		self::assertSame('120/60', $instance['x-rate-limit']);
		self::assertStringContainsString('configured metadata and local stats', $instance['summary']);

		$mastodon = $document['paths']['/api/mastodon/v1/statuses']['post'];
		self::assertSame('mastodon', $mastodon['tags'][0]);
		self::assertSame('mastodon_statuses_create', $mastodon['operationId']);
		self::assertSame([['bearerAuth' => []]], $mastodon['security']);
		self::assertSame('30/900', $mastodon['x-rate-limit']);
		self::assertSame(['write:statuses'], $mastodon['x-required-scopes']);

		$media = $document['paths']['/api/mastodon/v1/media']['post'];
		self::assertSame('mastodon', $media['tags'][0]);
		self::assertSame('mastodon_media_upload', $media['operationId']);
		self::assertSame([['bearerAuth' => []]], $media['security']);
		self::assertSame('20/900', $media['x-rate-limit']);
		self::assertSame(['write:media'], $media['x-required-scopes']);

		$mediaUpdate = $document['paths']['/api/mastodon/v1/media/{id}']['put'];
		self::assertSame('mastodon_media_update', $mediaUpdate['operationId']);
		self::assertSame([['bearerAuth' => []]], $mediaUpdate['security']);
		self::assertSame('30/900', $mediaUpdate['x-rate-limit']);
		self::assertSame(['write:media'], $mediaUpdate['x-required-scopes']);

		$mediaDelete = $document['paths']['/api/mastodon/v1/media/{id}']['delete'];
		self::assertSame('mastodon_media_delete', $mediaDelete['operationId']);
		self::assertSame([['bearerAuth' => []]], $mediaDelete['security']);
		self::assertSame('30/900', $mediaDelete['x-rate-limit']);
		self::assertSame(['write:media'], $mediaDelete['x-required-scopes']);

		$notifications = $document['paths']['/api/mastodon/v1/notifications']['get'];
		self::assertSame('mastodon_notifications_list', $notifications['operationId']);
		self::assertSame([['bearerAuth' => []]], $notifications['security']);
		self::assertSame('60/60', $notifications['x-rate-limit']);
		self::assertSame(['read:notifications'], $notifications['x-required-scopes']);

		$unread = $document['paths']['/api/mastodon/v1/notifications/unread_count']['get'];
		self::assertSame('mastodon_notifications_unread_count', $unread['operationId']);
		self::assertSame([['bearerAuth' => []]], $unread['security']);
		self::assertSame('60/60', $unread['x-rate-limit']);
		self::assertSame(['read:notifications'], $unread['x-required-scopes']);

		$dismiss = $document['paths']['/api/mastodon/v1/notifications/{id}/dismiss']['post'];
		self::assertSame('mastodon_notifications_dismiss', $dismiss['operationId']);
		self::assertSame([['bearerAuth' => []]], $dismiss['security']);
		self::assertSame('30/900', $dismiss['x-rate-limit']);
		self::assertSame(['write:notifications'], $dismiss['x-required-scopes']);
		self::assertSame(
			[['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
			$dismiss['parameters'],
		);

		$clear = $document['paths']['/api/mastodon/v1/notifications/clear']['post'];
		self::assertSame('mastodon_notifications_clear', $clear['operationId']);
		self::assertSame([['bearerAuth' => []]], $clear['security']);
		self::assertSame('30/900', $clear['x-rate-limit']);
		self::assertSame(['write:notifications'], $clear['x-required-scopes']);

		$search = $document['paths']['/api/mastodon/v1/search']['get'];
		self::assertSame('mastodon_search', $search['operationId']);
		self::assertSame([['bearerAuth' => []]], $search['security']);
		self::assertSame('60/60', $search['x-rate-limit']);
		self::assertSame(['read:search'], $search['x-required-scopes']);

		$account = $document['paths']['/api/mastodon/v1/accounts/{id}']['get'];
		self::assertSame('mastodon_accounts_show', $account['operationId']);
		self::assertArrayNotHasKey('security', $account);
		self::assertSame('120/60', $account['x-rate-limit']);
		self::assertSame(
			[['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
			$account['parameters'],
		);

		$followers = $document['paths']['/api/mastodon/v1/accounts/{id}/followers']['get'];
		self::assertSame('mastodon_accounts_followers', $followers['operationId']);
		self::assertArrayNotHasKey('security', $followers);
		self::assertSame('60/60', $followers['x-rate-limit']);

		$followingList = $document['paths']['/api/mastodon/v1/accounts/{id}/following']['get'];
		self::assertSame('mastodon_accounts_following', $followingList['operationId']);
		self::assertArrayNotHasKey('security', $followingList);
		self::assertSame('60/60', $followingList['x-rate-limit']);

		$relationships = $document['paths']['/api/mastodon/v1/accounts/relationships']['get'];
		self::assertSame('mastodon_accounts_relationships', $relationships['operationId']);
		self::assertSame([['bearerAuth' => []]], $relationships['security']);
		self::assertSame('60/60', $relationships['x-rate-limit']);
		self::assertSame(['read:follows'], $relationships['x-required-scopes']);

		$follow = $document['paths']['/api/mastodon/v1/accounts/{id}/follow']['post'];
		self::assertSame('mastodon_accounts_follow', $follow['operationId']);
		self::assertSame([['bearerAuth' => []]], $follow['security']);
		self::assertSame('30/900', $follow['x-rate-limit']);
		self::assertSame(['write:follows'], $follow['x-required-scopes']);

		$unfollow = $document['paths']['/api/mastodon/v1/accounts/{id}/unfollow']['post'];
		self::assertSame('mastodon_accounts_unfollow', $unfollow['operationId']);
		self::assertSame([['bearerAuth' => []]], $unfollow['security']);
		self::assertSame('30/900', $unfollow['x-rate-limit']);
		self::assertSame(['write:follows'], $unfollow['x-required-scopes']);

		$favourite = $document['paths']['/api/mastodon/v1/statuses/{id}/favourite']['post'];
		self::assertSame('mastodon_statuses_favourite', $favourite['operationId']);
		self::assertSame([['bearerAuth' => []]], $favourite['security']);
		self::assertSame('30/900', $favourite['x-rate-limit']);
		self::assertSame(['write:favourites'], $favourite['x-required-scopes']);

		$unfavourite = $document['paths']['/api/mastodon/v1/statuses/{id}/unfavourite']['post'];
		self::assertSame('mastodon_statuses_unfavourite', $unfavourite['operationId']);
		self::assertSame([['bearerAuth' => []]], $unfavourite['security']);
		self::assertSame('30/900', $unfavourite['x-rate-limit']);
		self::assertSame(['write:favourites'], $unfavourite['x-required-scopes']);

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
		self::assertArrayHasKey('/api/mastodon/v1/media/{id}', $decoded['paths']);
		self::assertArrayHasKey('put', $decoded['paths']['/api/mastodon/v1/media/{id}']);
		self::assertArrayHasKey('delete', $decoded['paths']['/api/mastodon/v1/media/{id}']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/{id}', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/{id}/followers', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/{id}/following', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/relationships', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/{id}/follow', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/accounts/{id}/unfollow', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/statuses/{id}/favourite', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/statuses/{id}/unfavourite', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/notifications/unread_count', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/notifications/{id}/dismiss', $decoded['paths']);
		self::assertArrayHasKey('/api/mastodon/v1/notifications/clear', $decoded['paths']);
		self::assertArrayHasKey('/api/v1/remote-posts/{id}/thread', $decoded['paths']);
		self::assertSame(
			[['bearerAuth' => []]],
			$decoded['paths']['/api/v1/remote-posts/{id}/thread']['get']['security'],
		);
	}
}
