<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Service\Mastodon\MastodonScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonCompatibilityTest extends TestCase
{
	public function testTheFeatureIsOffAndUsesTheCompatibilityPrefixByDefault(): void
	{
		$config = new ArrayConfig([]);

		self::assertFalse(MastodonApiConfig::enabled($config));
		self::assertSame('/api/mastodon/v1', MastodonApiConfig::prefix($config));
		self::assertTrue(MastodonApiConfig::enabled(new ArrayConfig([
			'social' => ['mastodon.api' => 'true'],
		])));
	}

	public function testAParentScopeGrantsItsChildrenButNotTheReverse(): void
	{
		self::assertTrue(MastodonScope::allows(['read'], 'read:accounts'));
		self::assertTrue(MastodonScope::allows(['read:accounts'], 'read:accounts'));
		self::assertFalse(MastodonScope::allows(['read:accounts'], 'read'));
		self::assertTrue(MastodonScope::allows(['*'], 'write:statuses'));
		self::assertFalse(MastodonScope::allows(['write'], 'read:accounts'));
		self::assertTrue(MastodonScope::allowsAll(['write'], ['write:statuses']));
		self::assertFalse(MastodonScope::allowsAll(['write'], ['write:statuses', 'read:accounts']));
	}

	#[DataProvider('unsupportedPrefixes')]
	public function testAPrefixThatCouldShadowTheCoreApiIsRejected(string $prefix): void
	{
		$this->expectException(ConfigurationException::class);

		MastodonApiConfig::normalizePrefix($prefix);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function unsupportedPrefixes(): iterable
	{
		yield 'empty' => [''];
		yield 'root' => ['/'];
		yield 'no leading slash' => ['mastodon'];
		yield 'trailing slash' => ['/mastodon/'];
		yield 'core api' => ['/api/v1'];
		yield 'core api child' => ['/api/v1/mastodon'];
		yield 'query' => ['/mastodon?api=1'];
		yield 'fragment' => ['/mastodon#x'];
		yield 'template' => ['/mastodon/{id}'];
		yield 'parent segment' => ['/mastodon/../api'];
		yield 'empty segment' => ['/mastodon//v1'];
		yield 'health' => ['/healthz'];
	}

	public function testAnAlternativePrefixIsAccepted(): void
	{
		self::assertSame('/compat/mastodon-v1', MastodonApiConfig::normalizePrefix('/compat/mastodon-v1'));
	}

	public function testTheMastodonRoutesCarryAuthScopesAndRateLimits(): void
	{
		$manifest = Routes::manifest();
		$create = $manifest->find('mastodon.statuses.create');
		$delete = $manifest->find('mastodon.statuses.delete');
		$verify = $manifest->find('mastodon.accounts.verify_credentials');
		$public = $manifest->find('mastodon.timelines.public');
		$media = $manifest->find('mastodon.media.upload');
		$mediaUpdate = $manifest->find('mastodon.media.update');
		$mediaDelete = $manifest->find('mastodon.media.delete');
		$favourite = $manifest->find('mastodon.statuses.favourite');
		$unfavourite = $manifest->find('mastodon.statuses.unfavourite');
		$relationships = $manifest->find('mastodon.accounts.relationships');
		$follow = $manifest->find('mastodon.accounts.follow');
		$unfollow = $manifest->find('mastodon.accounts.unfollow');

		self::assertNotNull($create);
		self::assertNotNull($delete);
		self::assertNotNull($verify);
		self::assertNotNull($public);
		self::assertNotNull($media);
		self::assertNotNull($mediaUpdate);
		self::assertNotNull($mediaDelete);
		self::assertNotNull($favourite);
		self::assertNotNull($unfavourite);
		self::assertNotNull($relationships);
		self::assertNotNull($follow);
		self::assertNotNull($unfollow);
		self::assertTrue($create->requiresAuth);
		self::assertSame(['write:statuses'], $create->requiredScopes);
		self::assertSame('30/900', $create->rateLimit);
		self::assertSame(['write:statuses'], $delete->requiredScopes);
		self::assertSame(['read:accounts'], $verify->requiredScopes);
		self::assertFalse($public->requiresAuth);
		self::assertSame('/api/mastodon/v1/timelines/public', $public->path);
		self::assertTrue($media->requiresAuth);
		self::assertSame(['write:media'], $media->requiredScopes);
		self::assertSame('20/900', $media->rateLimit);
		self::assertSame('/api/mastodon/v1/media', $media->path);
		self::assertTrue($mediaUpdate->requiresAuth);
		self::assertSame(['write:media'], $mediaUpdate->requiredScopes);
		self::assertSame('30/900', $mediaUpdate->rateLimit);
		self::assertSame('/api/mastodon/v1/media/{id}', $mediaUpdate->path);
		self::assertTrue($mediaDelete->requiresAuth);
		self::assertSame(['write:media'], $mediaDelete->requiredScopes);
		self::assertSame('30/900', $mediaDelete->rateLimit);
		self::assertSame('/api/mastodon/v1/media/{id}', $mediaDelete->path);
		self::assertTrue($favourite->requiresAuth);
		self::assertSame(['write:favourites'], $favourite->requiredScopes);
		self::assertSame('30/900', $favourite->rateLimit);
		self::assertSame('/api/mastodon/v1/statuses/{id}/favourite', $favourite->path);
		self::assertSame(['write:favourites'], $unfavourite->requiredScopes);
		self::assertSame('/api/mastodon/v1/statuses/{id}/unfavourite', $unfavourite->path);
		self::assertTrue($relationships->requiresAuth);
		self::assertSame(['read:follows'], $relationships->requiredScopes);
		self::assertSame('60/60', $relationships->rateLimit);
		self::assertSame('/api/mastodon/v1/accounts/relationships', $relationships->path);
		self::assertTrue($follow->requiresAuth);
		self::assertSame(['write:follows'], $follow->requiredScopes);
		self::assertSame('30/900', $follow->rateLimit);
		self::assertSame('/api/mastodon/v1/accounts/{id}/follow', $follow->path);
		self::assertTrue($unfollow->requiresAuth);
		self::assertSame(['write:follows'], $unfollow->requiredScopes);
		self::assertSame('30/900', $unfollow->rateLimit);
		self::assertSame('/api/mastodon/v1/accounts/{id}/unfollow', $unfollow->path);
	}
}
