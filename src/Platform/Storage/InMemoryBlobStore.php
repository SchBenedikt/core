<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

/**
 * A blob store that keeps everything in memory.
 *
 * Used by tests and by tools that must not touch the filesystem. It applies
 * the same path rules as the local store, so a test cannot pass on a path
 * production would refuse.
 */
final class InMemoryBlobStore implements BlobStore
{
	/**
	 * @var array<string, string>
	 */
	private array $blobs = [];

	public function put(string $path, string $contents): void
	{
		$this->blobs[BlobPath::normalize($path)] = $contents;
	}

	public function read(string $path): string
	{
		$path = BlobPath::normalize($path);
		if (!array_key_exists($path, $this->blobs)) {
			throw StorageException::notFound($path);
		}

		return $this->blobs[$path];
	}

	public function exists(string $path): bool
	{
		return array_key_exists(BlobPath::normalize($path), $this->blobs);
	}

	public function delete(string $path): void
	{
		unset($this->blobs[BlobPath::normalize($path)]);
	}

	public function size(string $path): int
	{
		return strlen($this->read($path));
	}

	public function list(string $prefix = ''): array
	{
		$paths = array_keys($this->blobs);
		sort($paths, SORT_STRING);

		// A trailing slash is a directory hint, not a segment: list("b/") and
		// list("b") must select the same blobs, exactly as the local store does.
		$prefix = rtrim($prefix, '/');
		if ($prefix === '') {
			return $paths;
		}

		$prefix = BlobPath::normalize($prefix);

		return array_values(array_filter(
			$paths,
			static fn (string $path): bool => $path === $prefix || str_starts_with($path, $prefix . '/'),
		));
	}
}
