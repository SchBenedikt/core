<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

/**
 * Validates and normalises the relative path of a blob.
 *
 * Both stores share this so a path that is refused by one is refused by the
 * other. Anything that could escape the store's root — absolute paths,
 * backslashes, `.`/`..` segments, empty segments and control characters — is
 * rejected rather than silently rewritten: a caller that builds a path from
 * user input should learn about it, not have it quietly repaired.
 */
final class BlobPath
{
	public const MAX_LENGTH = 1024;

	private function __construct()
	{
	}

	/**
	 * @throws StorageException
	 */
	public static function normalize(string $path): string
	{
		if ($path === '') {
			throw StorageException::invalidPath($path, 'empty');
		}

		if (strlen($path) > self::MAX_LENGTH) {
			throw StorageException::invalidPath($path, 'longer than ' . self::MAX_LENGTH . ' characters');
		}

		if (str_contains($path, '\\')) {
			throw StorageException::invalidPath($path, 'backslashes are not allowed');
		}

		if (str_starts_with($path, '/')) {
			throw StorageException::invalidPath($path, 'absolute paths are not allowed');
		}

		if (preg_match('/[\x00-\x1F]/', $path) === 1) {
			throw StorageException::invalidPath($path, 'control characters are not allowed');
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw StorageException::invalidPath($path, 'empty or relative segments are not allowed');
			}
		}

		return $path;
	}
}
