<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

use Throwable;

use function array_unique;
use function array_values;
use function count;
use function filter_var;
use function fclose;
use function feof;
use function fopen;
use function fwrite;
use function in_array;
use function is_resource;
use function preg_match;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function stream_get_contents;
use function stream_get_meta_data;
use function substr;
use function trim;

/**
 * BlobStore adapter over a host-configured S3-compatible client.
 *
 * This class owns key validation, bucket/prefix isolation, bounded reads and
 * the listing contract. The host injects a client configured with its
 * endpoint, region, credentials and timeouts; secrets never enter Core config.
 */
final class S3BlobStore implements StreamingBlobStore
{
	public const DEFAULT_MAX_OBJECT_BYTES = 268_435_456;
	public const DEFAULT_LIST_PAGE_SIZE = 1000;

	private readonly string $keyPrefix;

	public function __construct(
		private readonly S3CompatibleClient $client,
		private readonly string $bucket,
		string $keyPrefix = '',
		private readonly ?string $serverSideEncryption = null,
		private readonly ?string $kmsKeyId = null,
		private readonly int $maxObjectBytes = self::DEFAULT_MAX_OBJECT_BYTES,
		private readonly int $listPageSize = self::DEFAULT_LIST_PAGE_SIZE,
	) {
		if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1
			|| filter_var($bucket, FILTER_VALIDATE_IP) !== false
			|| str_contains($bucket, '..')
			|| str_contains($bucket, '.-')
			|| str_contains($bucket, '-.')) {
			throw new StorageException('The S3 bucket name is invalid.');
		}
		$this->keyPrefix = $keyPrefix === '' ? '' : BlobPath::normalize($keyPrefix);
		if (!in_array($serverSideEncryption, [null, 'AES256', 'aws:kms'], true)) {
			throw new StorageException('The S3 server-side encryption mode is invalid.');
		}
		if ($kmsKeyId !== null && ($serverSideEncryption !== 'aws:kms' || trim($kmsKeyId) === '' || trim($kmsKeyId) !== $kmsKeyId || strlen($kmsKeyId) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $kmsKeyId) === 1)) {
			throw new StorageException('The S3 KMS key id is invalid.');
		}
		if ($maxObjectBytes < 0 || $listPageSize < 1 || $listPageSize > 1000) {
			throw new StorageException('The S3 transfer or listing limit is invalid.');
		}
		if ($maxObjectBytes >= PHP_INT_MAX) {
			throw new StorageException('The S3 object limit is too large for bounded reads.');
		}
	}

	public function put(string $path, string $contents): void
	{
		$this->assertLength(strlen($contents), $path);
		$stream = fopen('php://temp/maxmemory:2097152', 'w+b');
		if (!is_resource($stream) || !$this->writeAll($stream, $contents) || !rewind($stream)) {
			if (is_resource($stream)) {
				fclose($stream);
			}
			throw StorageException::io('write', $path, 'cannot prepare a bounded upload stream');
		}
		try {
			$this->putStream($path, $stream, strlen($contents));
		} finally {
			fclose($stream);
		}
	}

	public function putStream(string $path, mixed $contents, int $length): void
	{
		$key = $this->key($path);
		$this->assertLength($length, $path);
		if (!is_resource($contents) || !$this->isReadable($contents)) {
			throw StorageException::io('write', $path, 'the upload body must be a readable stream');
		}
		$this->run('write', $path, fn () => $this->client->putObject(
			$this->bucket,
			$key,
			$contents,
			$length,
			$this->serverSideEncryption,
			$this->kmsKeyId,
		));
	}

	public function read(string $path): string
	{
		$stream = $this->readStream($path);
		try {
			$contents = stream_get_contents($stream, $this->maxObjectBytes + 1);
			if ($contents === false || strlen($contents) > $this->maxObjectBytes || !feof($stream)) {
				throw StorageException::io('read', $path, 'the object exceeds the configured read limit');
			}
			return $contents;
		} finally {
			fclose($stream);
		}
	}

	public function readStream(string $path): mixed
	{
		$key = $this->key($path);
		$stream = $this->run('read', $path, fn () => $this->client->getObject($this->bucket, $key, $this->maxObjectBytes));
		if ($stream === null) {
			throw StorageException::notFound($path);
		}
		if (!is_resource($stream) || !$this->isReadable($stream)) {
			if (is_resource($stream)) {
				fclose($stream);
			}
			throw StorageException::io('read', $path, 'the S3 client returned an invalid stream');
		}
		return $stream;
	}

	public function exists(string $path): bool
	{
		$key = $this->key($path);
		return $this->run('check', $path, fn () => $this->client->headObject($this->bucket, $key)) !== null;
	}

	public function delete(string $path): void
	{
		$key = $this->key($path);
		$this->run('delete', $path, fn () => $this->client->deleteObject($this->bucket, $key));
	}

	public function size(string $path): int
	{
		$key = $this->key($path);
		$size = $this->run('stat', $path, fn () => $this->client->headObject($this->bucket, $key));
		if ($size === null) {
			throw StorageException::notFound($path);
		}
		if (!is_int($size) || $size < 0 || $size > $this->maxObjectBytes) {
			throw StorageException::io('stat', $path, 'the object size is outside configured limits');
		}
		return $size;
	}

	public function list(string $prefix = ''): array
	{
		$requestedPrefix = $prefix;
		if ($prefix !== '' && str_ends_with($prefix, '/')) {
			$prefix = substr($prefix, 0, -1);
		}
		if ($prefix === '' && $requestedPrefix !== '') {
			throw StorageException::invalidPath($requestedPrefix, 'absolute paths are not allowed');
		}
		if ($prefix !== '') {
			$prefix = BlobPath::normalize($prefix);
		}
		$objectPrefix = $this->joinKey($prefix);
		$token = null;
		$seenTokens = [];
		$paths = [];
		do {
			$page = $this->run('list', $prefix, fn () => $this->client->listObjects(
				$this->bucket,
				$objectPrefix,
				$token,
				$this->listPageSize,
			));
			if (count($page->keys) > $this->listPageSize) {
				throw StorageException::io('list', $prefix, 'the S3 client exceeded the configured page size');
			}
			foreach ($page->keys as $key) {
				if (!str_starts_with($key, $objectPrefix)) {
					throw StorageException::io('list', $prefix, 'the S3 client returned a key outside the requested prefix');
				}
				$relative = substr($key, strlen($this->keyPrefix) + ($this->keyPrefix === '' ? 0 : 1));
				if ($relative === '') {
					throw StorageException::io('list', $prefix, 'the S3 client returned an unexpected key');
				}
				if ($prefix !== '' && $relative !== $prefix && !str_starts_with($relative, $prefix . '/')) {
					// S3 prefix matching is byte-based, while BlobStore prefixes
					// match path segments; discard sibling names like "posts-old".
					continue;
				}
				$paths[] = BlobPath::normalize($relative);
			}
			$token = $page->nextContinuationToken;
			if ($token !== null) {
				if (isset($seenTokens[$token])) {
					throw StorageException::io('list', $prefix, 'the S3 client repeated a continuation token');
				}
				$seenTokens[$token] = true;
			}
		} while ($token !== null);

		sort($paths, SORT_STRING);
		return array_values(array_unique($paths));
	}

	private function key(string $path): string
	{
		$key = $this->joinKey(BlobPath::normalize($path));
		if (strlen($key) > BlobPath::MAX_LENGTH) {
			throw StorageException::invalidPath($path, 'the S3 key including its prefix is too long');
		}
		return $key;
	}

	private function joinKey(string $path): string
	{
		if ($this->keyPrefix === '') {
			return $path;
		}
		return $path === '' ? $this->keyPrefix . '/' : $this->keyPrefix . '/' . $path;
	}

	private function assertLength(int $length, string $path): void
	{
		if ($length < 0 || $length > $this->maxObjectBytes) {
			throw StorageException::io('write', $path, 'the object exceeds the configured transfer limit');
		}
	}

	/** @param resource $stream */
	private function isReadable(mixed $stream): bool
	{
		$mode = (string) (stream_get_meta_data($stream)['mode'] ?? '');
		return str_contains($mode, 'r') || str_contains($mode, '+');
	}

	/** @param resource $stream */
	private function writeAll(mixed $stream, string $contents): bool
	{
		$offset = 0;
		$length = strlen($contents);
		while ($offset < $length) {
			$written = fwrite($stream, substr($contents, $offset));
			if ($written === false || $written === 0) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private function run(string $operation, string $path, callable $callback): mixed
	{
		try {
			return $callback();
		} catch (Throwable) {
			throw StorageException::io($operation, $path, 'the S3-compatible request failed');
		}
	}
}
