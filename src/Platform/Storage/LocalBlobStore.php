<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Blob storage in a directory on the server's filesystem.
 *
 * Writes go to a temporary file in the destination directory and are then
 * renamed into place, so a reader never sees a half-written blob and a crash
 * mid-write leaves the previous version intact.
 */
final class LocalBlobStore implements BlobStore
{
	private const DIRECTORY_MODE = 0o770;
	private const FILE_MODE = 0o660;

	private readonly string $root;

	public function __construct(string $root)
	{
		$root = rtrim($root, '/');
		if ($root === '') {
			throw new StorageException('The blob store root must not be empty.');
		}

		$this->root = $root;
	}

	public function put(string $path, string $contents): void
	{
		$path = BlobPath::normalize($path);
		$target = $this->root . '/' . $path;
		$directory = dirname($target);

		$this->ensureDirectory($directory, $path);

		$temporary = tempnam($directory, '.partial-');
		if ($temporary === false) {
			throw StorageException::io('write', $path, 'cannot create a temporary file in ' . $directory);
		}

		try {
			if (file_put_contents($temporary, $contents) === false) {
				throw StorageException::io('write', $path, 'cannot write the temporary file');
			}

			@chmod($temporary, self::FILE_MODE);

			if (!@rename($temporary, $target)) {
				throw StorageException::io('write', $path, 'cannot move the temporary file into place');
			}
		} catch (\Throwable $e) {
			if (is_file($temporary)) {
				@unlink($temporary);
			}

			throw $e;
		}
	}

	public function read(string $path): string
	{
		$path = BlobPath::normalize($path);
		$full = $this->root . '/' . $path;

		if (!is_file($full)) {
			throw StorageException::notFound($path);
		}

		$contents = file_get_contents($full);
		if ($contents === false) {
			throw StorageException::io('read', $path, 'the file exists but could not be read');
		}

		return $contents;
	}

	public function exists(string $path): bool
	{
		return is_file($this->root . '/' . BlobPath::normalize($path));
	}

	public function delete(string $path): void
	{
		$path = BlobPath::normalize($path);
		$full = $this->root . '/' . $path;

		if (!is_file($full)) {
			return;
		}

		if (!@unlink($full)) {
			throw StorageException::io('delete', $path, 'the file could not be removed');
		}
	}

	public function size(string $path): int
	{
		$path = BlobPath::normalize($path);
		$full = $this->root . '/' . $path;

		if (!is_file($full)) {
			throw StorageException::notFound($path);
		}

		$size = filesize($full);
		if ($size === false) {
			throw StorageException::io('stat', $path, 'the size could not be read');
		}

		return $size;
	}

	public function list(string $prefix = ''): array
	{
		if (!is_dir($this->root)) {
			return [];
		}

		$paths = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY,
		);

		/** @var SplFileInfo $file */
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			$path = substr($file->getPathname(), strlen($this->root) + 1);
			if ($prefix !== '' && !str_starts_with($path, rtrim($prefix, '/') . '/')) {
				continue;
			}

			$paths[] = $path;
		}

		sort($paths, SORT_STRING);

		return $paths;
	}

	private function ensureDirectory(string $directory, string $path): void
	{
		if (is_dir($directory)) {
			return;
		}

		if (!@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
			throw StorageException::io('write', $path, 'cannot create the directory ' . $directory);
		}
	}
}
