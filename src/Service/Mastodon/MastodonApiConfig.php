<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Config\Config;

/**
 * Configuration and validation for the optional Mastodon-compatible surface.
 *
 * The prefix is a path, not a host name. It is deliberately separate from
 * `/api/v1` so enabling the compatibility API can never shadow or change the
 * core API; a bad prefix fails at boot instead of producing a route collision.
 */
final class MastodonApiConfig
{
	public const APP = 'social';

	public const ENABLED = 'mastodon.api';

	public const PREFIX = 'mastodon.prefix';

	public const DEFAULT_PREFIX = '/api/mastodon/v1';

	public static function enabled(Config $config): bool
	{
		return $config->getBool(self::APP, self::ENABLED, false);
	}

	public static function prefix(Config $config): string
	{
		$configured = $config->get(self::APP, self::PREFIX);
		if ($configured !== null && !is_string($configured)) {
			throw new ConfigurationException('The Mastodon API prefix must be a string.');
		}

		return self::normalizePrefix($configured ?? self::DEFAULT_PREFIX);
	}

	public static function normalizePrefix(string $prefix): string
	{
		$prefix = trim($prefix);
		if ($prefix === '' || $prefix[0] !== '/' || $prefix === '/' || str_ends_with($prefix, '/')) {
			throw new ConfigurationException('The Mastodon API prefix must be a non-root path without a trailing slash.');
		}

		if (str_contains($prefix, '?') || str_contains($prefix, '#') || str_contains($prefix, '{')) {
			throw new ConfigurationException('The Mastodon API prefix must be a plain path.');
		}

		if (str_contains($prefix, '//') || str_contains($prefix, '..')) {
			throw new ConfigurationException('The Mastodon API prefix must not contain empty or parent segments.');
		}

		if (preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#', $prefix) !== 1) {
			throw new ConfigurationException('The Mastodon API prefix contains an unsupported path segment.');
		}

		if ($prefix === '/api/v1' || str_starts_with($prefix, '/api/v1/')) {
			throw new ConfigurationException('The Mastodon API prefix must not overlap /api/v1.');
		}

		foreach (['/healthz', '/inbox', '/@', '/.well-known'] as $reserved) {
			if ($prefix === $reserved || str_starts_with($prefix, $reserved . '/')) {
				throw new ConfigurationException('The Mastodon API prefix must not overlap ' . $reserved . '.');
			}
		}

		return $prefix;
	}
}
