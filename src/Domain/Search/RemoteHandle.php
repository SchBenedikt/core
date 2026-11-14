<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use InvalidArgumentException;

use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function rawurlencode;
use function strrpos;
use function str_contains;
use function substr;
use function trim;

/**
 * A normalized `user@host` handle for exact remote lookup.
 *
 * The parser accepts only a handle, never an arbitrary URL. That is the API
 * boundary that stops a client from asking the server to fetch any address;
 * the destination is always derived from the host part of an account handle
 * and then checked again by {@see \AlohaSocial\Core\Service\Federation\RemoteFetcher}.
 */
final readonly class RemoteHandle
{
	public const MAX_LENGTH = 320;

	public function __construct(
		public string $user,
		public string $host,
	) {
		if ($user === '' || $host === '') {
			throw new InvalidArgumentException('A remote handle needs a user and a host.');
		}
	}

	public static function tryFrom(string $value): ?self
	{
		$value = trim($value);
		if ($value === '' || str_contains($value, '://')) {
			return null;
		}

		if ($value[0] === '@') {
			$value = substr($value, 1);
		}

		$at = strrpos($value, '@');
		if ($at === false) {
			return null;
		}

		$user = substr($value, 0, $at);
		$host = mb_strtolower(substr($value, $at + 1));

		if ($user === '' || $host === '' || mb_strlen($value) > self::MAX_LENGTH) {
			return null;
		}

		if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) !== 1) {
			return null;
		}

		if (preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$/', $host) !== 1) {
			return null;
		}

		if (str_contains($host, '..') || str_contains($host, '@') || str_contains($host, '/')) {
			return null;
		}

		return new self(mb_strtolower($user), $host);
	}

	public function normalized(): string
	{
		return $this->user . '@' . $this->host;
	}

	public function acct(): string
	{
		return '@' . $this->normalized();
	}

	public function webfingerUrl(): string
	{
		return 'https://' . $this->host . '/.well-known/webfinger?resource='
			. rawurlencode('acct:' . $this->normalized());
	}
}
