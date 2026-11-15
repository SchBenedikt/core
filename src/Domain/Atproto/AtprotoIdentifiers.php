<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use function preg_match;
use function strlen;
use function trim;

/**
 * Validation for the identifiers that cross a relay, Jetstream or AppView
 * boundary.
 *
 * The importer stores opaque records, but it never stores an identifier that
 * could escape its namespace: every URI is rebuilt from a validated DID,
 * collection and record key instead of being trusted as supplied.
 */
final class AtprotoIdentifiers
{
	public const MAX_DID_LENGTH = 512;

	public const MAX_COLLECTION_LENGTH = 128;

	public const MAX_RKEY_LENGTH = 512;

	public const MAX_HANDLE_LENGTH = 253;

	private function __construct()
	{
	}

	public static function isDid(mixed $value): bool
	{
		return is_string($value)
			&& strlen($value) <= self::MAX_DID_LENGTH
			&& preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/D', $value) === 1;
	}

	public static function isCollection(mixed $value): bool
	{
		return is_string($value)
			&& strlen($value) <= self::MAX_COLLECTION_LENGTH
			&& preg_match('/^[A-Za-z][A-Za-z0-9-]*(?:\.[A-Za-z0-9][A-Za-z0-9-]*)+$/D', $value) === 1;
	}

	public static function isRkey(mixed $value): bool
	{
		return is_string($value)
			&& $value !== ''
			&& strlen($value) <= self::MAX_RKEY_LENGTH
			&& preg_match('/^[A-Za-z0-9._:~-]+$/D', $value) === 1;
	}

	public static function isHandle(mixed $value): bool
	{
		return is_string($value)
			&& $value !== ''
			&& strlen($value) <= self::MAX_HANDLE_LENGTH
			&& preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $value) === 1
			&& str_contains($value, '.');
	}

	public static function uri(string $did, string $collection, string $rkey): string
	{
		return 'at://' . $did . '/' . $collection . '/' . $rkey;
	}

	/**
	 * Validates an AT URI used by a reply or embed. Query and fragment parts
	 * are not meaningful for a repository record and are refused.
	 */
	public static function isAtUri(mixed $value): bool
	{
		if (!is_string($value) || strlen($value) > 512) {
			return false;
		}

		return preg_match(
			'#^at://(did:[a-z0-9]+:[A-Za-z0-9._:%-]+)/([A-Za-z][A-Za-z0-9-]*(?:\.[A-Za-z0-9][A-Za-z0-9-]*)+)/([A-Za-z0-9._:~-]+)$#D',
			trim($value),
		) === 1;
	}
}
