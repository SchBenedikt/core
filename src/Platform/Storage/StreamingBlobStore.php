<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

/**
 * Optional streaming operations for blob stores used with large objects.
 */
interface StreamingBlobStore extends BlobStore
{
	/**
	 * Writes exactly $length bytes from a readable stream.
	 *
	 * The caller retains ownership of the stream and closes it.
	 *
	 * @param resource $contents
	 */
	public function putStream(string $path, mixed $contents, int $length): void;

	/**
	 * Returns a readable stream; the caller owns and closes it.
	 *
	 * @return resource
	 */
	public function readStream(string $path): mixed;
}
