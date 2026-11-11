<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

use RuntimeException;

/**
 * Raised when a blob cannot be read, written, listed or addressed.
 */
class StorageException extends RuntimeException
{
	public static function notFound(string $path): self
	{
		return new self('Blob not found: ' . $path);
	}

	public static function invalidPath(string $path, string $reason): self
	{
		return new self('Invalid blob path "' . $path . '": ' . $reason);
	}

	public static function io(string $operation, string $path, string $reason): self
	{
		return new self('Cannot ' . $operation . ' blob "' . $path . '": ' . $reason);
	}
}
