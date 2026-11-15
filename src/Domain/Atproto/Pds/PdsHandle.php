<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use InvalidArgumentException;

/**
 * Validation and defaulting for at:// handles.
 */
final class PdsHandle
{
	private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

	private function __construct()
	{
	}

	public static function assertValid(string $handle): void
	{
		if ($handle === '' || strlen($handle) > 253 || !str_contains($handle, '.')) {
			throw new InvalidArgumentException('A PDS handle must be a dotted lowercase host name.');
		}

		foreach (explode('.', $handle) as $label) {
			if (preg_match(self::LABEL, $label) !== 1) {
				throw new InvalidArgumentException('A PDS handle contains an invalid label.');
			}
		}
	}

	public static function defaultFor(string $localUserId, string $domain): string
	{
		$name = strtolower($localUserId);
		$name = preg_replace('/[^a-z0-9-]+/', '-', $name) ?? '';
		$name = trim($name, '-');
		if ($name === '') {
			$name = 'user';
		}

		$name = substr($name, 0, 63);
		$domain = strtolower(trim($domain, '.'));
		if ($domain === '') {
			throw new InvalidArgumentException('No PDS handle domain is configured; pass a handle explicitly.');
		}

		$handle = $name . '.' . $domain;
		self::assertValid($handle);

		return $handle;
	}
}
