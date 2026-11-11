<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

/**
 * Binary storage for media, cached avatars and other files.
 *
 * The Nextcloud host backs this with appdata, the standalone host with a
 * local directory or an S3-compatible bucket. Paths are relative, always use
 * `/`, and are validated by {@see BlobPath}.
 */
interface BlobStore
{
	/**
	 * Writes a blob, replacing it atomically.
	 *
	 * @throws StorageException
	 */
	public function put(string $path, string $contents): void;

	/**
	 * @throws StorageException when the blob does not exist
	 */
	public function read(string $path): string;

	public function exists(string $path): bool;

	/**
	 * Deletes a blob. Deleting a blob that does not exist is not an error.
	 *
	 * @throws StorageException
	 */
	public function delete(string $path): void;

	/**
	 * @throws StorageException when the blob does not exist
	 */
	public function size(string $path): int;

	/**
	 * All blobs below an optional prefix, as relative paths, sorted.
	 *
	 * @return list<string>
	 *
	 * @throws StorageException
	 */
	public function list(string $prefix = ''): array;
}
