<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Platform\Config\Config;
use InvalidArgumentException;

/**
 * Host configuration for the native PDS.
 *
 * The service is off unless a host explicitly enables it. The public URL is
 * the endpoint written into DID documents; the handle domain is used only when
 * a createDid call omits a handle.
 */
final class PdsConfig
{
	public const APP = 'social';

	public const ENABLED = 'atproto.pds.enabled';

	public const PUBLIC_URL = 'atproto.pds.public_url';

	public const HANDLE_DOMAIN = 'atproto.pds.handle_domain';

	public const SECRET_BOX_KEY = 'atproto.pds.secret_box_key';

	public static function enabled(Config $config): bool
	{
		return $config->getBool(self::APP, self::ENABLED, false);
	}

	public static function publicUrl(Config $config): string
	{
		$value = rtrim($config->get(self::APP, self::PUBLIC_URL, '') ?? '', '/');
		$parts = parse_url($value);
		if ($value === ''
			|| $parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new InvalidArgumentException('atproto.pds.public_url must be an absolute http(s) URL.');
		}

		return $value;
	}

	public static function handleDomain(Config $config): string
	{
		return strtolower(trim($config->get(self::APP, self::HANDLE_DOMAIN, '') ?? ''));
	}

	public static function secretBoxKey(Config $config): ?string
	{
		$value = $config->get(self::APP, self::SECRET_BOX_KEY, '') ?? '';

		return $value === '' ? null : $value;
	}
}
