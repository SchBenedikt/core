<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

/**
 * A local account's connection to an AT Protocol PDS.
 *
 * The host owns account linking and token refresh. This object only carries
 * what one authenticated XRPC write needs; the access token is deliberately
 * not exposed by any API response or log line.
 */
final readonly class AtprotoAccount
{
	public const MAX_TOKEN_LENGTH = 8192;

	public function __construct(
		public string $did,
		public string $handle,
		public string $pdsUrl,
		public string $accessToken,
	) {
		if (preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
			throw new InvalidArgumentException('An AT Protocol account needs a valid DID.');
		}

		if (!$this->isValidHandle($handle)) {
			throw new InvalidArgumentException('An AT Protocol account needs a valid handle.');
		}

		$parts = parse_url($pdsUrl);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| (string) $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		) {
			throw new InvalidArgumentException('An AT Protocol account needs an absolute http(s) PDS URL.');
		}

		if ($accessToken === '' || trim($accessToken) !== $accessToken) {
			throw new InvalidArgumentException('An AT Protocol account needs an access token.');
		}

		if (strlen($accessToken) > self::MAX_TOKEN_LENGTH) {
			throw new InvalidArgumentException('The AT Protocol access token is too long.');
		}
	}

	private function isValidHandle(string $handle): bool
	{
		if ($handle === '' || strlen($handle) > 253 || $handle !== strtolower($handle)) {
			return false;
		}

		$labels = explode('.', $handle);
		if (count($labels) < 2) {
			return false;
		}

		foreach ($labels as $label) {
			if ($label === ''
				|| strlen($label) > 63
				|| preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
			) {
				return false;
			}
		}

		return true;
	}
}
