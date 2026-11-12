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

		self::assertNotNull($create);
		self::assertNotNull($delete);
		self::assertNotNull($verify);
		self::assertNotNull($public);
		self::assertNotNull($media);
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
	}
}
