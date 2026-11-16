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
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
	public function testDeclaresTheLocalApi(): void
	{
		$manifest = Routes::manifest();

		self::assertCount(186, $manifest->routes());
		self::assertNotNull($manifest->find('recommendations.get'));
		self::assertNotNull($manifest->find('recommendations.preferences.get'));
		self::assertNotNull($manifest->find('recommendations.preferences.update'));
		self::assertNotNull($manifest->find('recommendations.feedback.create'));
		self::assertNotNull($manifest->find('recommendations.signals.create'));
		self::assertNotNull($manifest->find('recommendations.export'));
		self::assertNotNull($manifest->find('recommendations.data.delete'));
		self::assertNotNull($manifest->find('posts.create'));
		self::assertNotNull($manifest->find('posts.boost'));
		self::assertNotNull($manifest->find('posts.unboost'));
		self::assertNotNull($manifest->find('atproto.publish'));
		self::assertNotNull($manifest->find('atproto.publication'));
		self::assertNotNull($manifest->find('timelines.home'));
		self::assertNotNull($manifest->find('inbox'));
		self::assertNotNull($manifest->find('actors.get'));
		self::assertNotNull($manifest->find('actors.followers'));
		self::assertNotNull($manifest->find('actors.following'));
		self::assertNotNull($manifest->find('blocks.list'));
		self::assertNotNull($manifest->find('blocks.block'));
		self::assertNotNull($manifest->find('blocks.unblock'));
		self::assertNotNull($manifest->find('media.upload'));
		self::assertNotNull($manifest->find('media.get'));
		self::assertNotNull($manifest->find('following.follow'));
		self::assertNotNull($manifest->find('following.unfollow'));
		self::assertNotNull($manifest->find('actors.outbox'));
		self::assertNotNull($manifest->find('webfinger'));
		self::assertNotNull($manifest->find('threads.get'));
		self::assertNotNull($manifest->find('mastodon.search'));
		self::assertNotNull($manifest->find('mastodon.accounts.relationships'));
		self::assertNotNull($manifest->find('mastodon.accounts.show'));
		self::assertNotNull($manifest->find('mastodon.accounts.followers'));
		self::assertNotNull($manifest->find('mastodon.accounts.following'));
		self::assertNotNull($manifest->find('mastodon.accounts.follow'));
		self::assertNotNull($manifest->find('mastodon.accounts.unfollow'));
		self::assertNotNull($manifest->find('mastodon.accounts.mute'));
		self::assertNotNull($manifest->find('mastodon.accounts.unmute'));
		self::assertNotNull($manifest->find('mastodon.mutes'));
		self::assertNotNull($manifest->find('mastodon.lists.list'));
		self::assertNotNull($manifest->find('mastodon.lists.create'));
		self::assertNotNull($manifest->find('mastodon.lists.show'));
		self::assertNotNull($manifest->find('mastodon.lists.update'));
		self::assertNotNull($manifest->find('mastodon.lists.delete'));
		self::assertNotNull($manifest->find('mastodon.lists.accounts'));
		self::assertNotNull($manifest->find('mastodon.lists.accounts.add'));
		self::assertNotNull($manifest->find('mastodon.lists.accounts.remove'));
		self::assertNotNull($manifest->find('mastodon.timelines.list'));
		self::assertNotNull($manifest->find('mastodon.polls.show'));
		self::assertNotNull($manifest->find('mastodon.polls.vote'));
		self::assertNotNull($manifest->find('mastodon.statuses.reblog'));
		self::assertNotNull($manifest->find('mastodon.statuses.unreblog'));
		self::assertNotNull($manifest->find('mastodon.statuses.context'));
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
			if (in_array($route->name, ['health', 'inbox', 'inbox.shared', 'actors.get', 'actors.followers', 'actors.following', 'actors.featured', 'actors.collections', 'actors.outbox', 'webfinger'], true)) {
				continue;
			}

			if (str_starts_with($route->name, 'mastodon.')) {
				self::assertStringStartsWith(MastodonApiConfig::DEFAULT_PREFIX . '/', $route->path);

				continue;
			}

			if (str_starts_with($route->name, 'xrpc.')) {
				self::assertStringStartsWith('/xrpc/com.atproto.', $route->path);

				continue;
			}

			self::assertStringStartsWith(ApiVersion::BASE_PATH . '/', $route->path);
		}
	}

	public function testOnlyTheSignedProtocolInboxesAllowAnUnauthenticatedMutation(): void
	{
		$protocolInboxes = ['inbox', 'inbox.shared'];

		foreach (Routes::manifest()->routes() as $route) {
			if (in_array($route->method, ['GET', 'HEAD', 'OPTIONS'], true) || $route->requiresAuth) {
				continue;
			}

			self::assertContains(
				$route->name,
				$protocolInboxes,
				'The mutating route ' . $route->name . ' must require a local account or be a signed protocol inbox.',
			);
		}
	}

	public function testTheWriteRouteIsProtectedAndRateLimited(): void
	{
		$route = Routes::manifest()->find('posts.create');

		self::assertNotNull($route);
		self::assertTrue($route->requiresAuth);
		self::assertSame('30/900', $route->rateLimit);
	}

	public function testTheBlockRoutesAreProtectedAndRateLimited(): void
	{
		$list = Routes::manifest()->find('blocks.list');
		$block = Routes::manifest()->find('blocks.block');
		$unblock = Routes::manifest()->find('blocks.unblock');

		self::assertNotNull($list);
		self::assertTrue($list->requiresAuth);
		self::assertSame('120/60', $list->rateLimit);
		self::assertSame('/api/v1/blocks', $list->path);

		self::assertNotNull($block);
		self::assertTrue($block->requiresAuth);
		self::assertSame('30/900', $block->rateLimit);
		self::assertSame('/api/v1/blocks', $block->path);

		self::assertNotNull($unblock);
		self::assertTrue($unblock->requiresAuth);
		self::assertSame('60/900', $unblock->rateLimit);
		self::assertSame('/api/v1/blocks/{id}', $unblock->path);
	}

	public function testTheMediaRoutesHaveTheirAuthAndRateLimits(): void
	{
		$upload = Routes::manifest()->find('media.upload');
		$get = Routes::manifest()->find('media.get');

		self::assertNotNull($upload);
		self::assertTrue($upload->requiresAuth);
		self::assertSame('20/900', $upload->rateLimit);
		self::assertSame('/api/v1/media', $upload->path);

		self::assertNotNull($get);
		self::assertFalse($get->requiresAuth, 'Media must be public for remote servers to fetch it.');
		self::assertSame('240/60', $get->rateLimit);
		self::assertSame('/api/v1/media/{id}', $get->path);
	}

	public function testTheFollowingWriteRoutesAreProtectedAndRateLimited(): void
	{
		$follow = Routes::manifest()->find('following.follow');
		$unfollow = Routes::manifest()->find('following.unfollow');

		self::assertNotNull($follow);
		self::assertTrue($follow->requiresAuth);
		self::assertSame('30/900', $follow->rateLimit);
		self::assertSame('/api/v1/following', $follow->path);

		self::assertNotNull($unfollow);
		self::assertTrue($unfollow->requiresAuth);
		self::assertSame('60/900', $unfollow->rateLimit);
		self::assertSame('/api/v1/following/{id}', $unfollow->path);
	}

	public function testTheTimelineAndPostRoutesArePubliclyReadable(): void
	{
		foreach (['posts.get', 'timelines.public', 'instance.get', 'health'] as $name) {
			$route = Routes::manifest()->find($name);

			self::assertNotNull($route);
			self::assertFalse($route->requiresAuth, 'Route ' . $name . ' must be public.');
		}
	}

	public function testTheThreadRouteIsProtectedAndRateLimited(): void
	{
		$route = Routes::manifest()->find('threads.get');

		self::assertNotNull($route);
		self::assertTrue($route->requiresAuth);
		self::assertSame('60/60', $route->rateLimit);
		self::assertSame('/api/v1/remote-posts/{id}/thread', $route->path);
	}

	public function testTheInboxIsPublicButRateLimited(): void
	{
		$route = Routes::manifest()->find('inbox');

		self::assertNotNull($route);
		self::assertFalse($route->requiresAuth, 'The inbox authenticates by signature, not by session.');
		self::assertSame('120/60', $route->rateLimit);
		self::assertSame('/inbox', $route->path);

		$shared = Routes::manifest()->find('inbox.shared');

		self::assertNotNull($shared);
		self::assertFalse($shared->requiresAuth, 'The shared inbox also authenticates by signature.');
		self::assertSame('120/60', $shared->rateLimit);
		self::assertSame('/inbox/shared', $shared->path);
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
		self::assertCount(186, $decoded['routes']);
		self::assertSame('/api/v1/posts', $decoded['routes'][2]['path']);
	}

	public function testNoRouteIsMissingAHandlerAtBoot(): void
	{
		// Application::inMemory() refuses to boot when the manifest names a
		// handler nobody registered; this test is the regression guard for it.
		$app = Application::inMemory();

		self::assertCount(186, $app->routes->routes());
	}
}
