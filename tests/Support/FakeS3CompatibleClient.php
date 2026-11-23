<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Platform\Storage\S3CompatibleClient;
use AlohaSocial\Core\Platform\Storage\S3ObjectPage;
use AlohaSocial\Core\Platform\Storage\StorageException;

use function array_key_exists;
use function array_slice;
use function count;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function rewind;
use function sort;
use function strlen;
use function stream_get_contents;
use function str_starts_with;
use function substr;

final class FakeS3CompatibleClient implements S3CompatibleClient
{
	/** @var array<string, string> */
	public array $objects = [];

	/** @var list<array{bucket: string, key: string, encryption: ?string, kms: ?string}> */
	public array $puts = [];

	/** @var list<int> */
	public array $readLimits = [];

	/** @var list<int> */
	public array $pageSizes = [];

	public bool $repeatContinuationToken = false;

	public ?string $failOperation = null;

	public function putObject(
		string $bucket,
		string $key,
		mixed $body,
		int $contentLength,
		?string $serverSideEncryption,
		?string $kmsKeyId,
	): void {
		$this->failIf('put');
		if (!is_resource($body)) {
			throw new StorageException('body must be a stream');
		}
		$contents = stream_get_contents($body);
		if ($contents === false || strlen($contents) !== $contentLength) {
			throw new StorageException('body length mismatch');
		}
		$this->objects[$bucket . '/' . $key] = $contents;
		$this->puts[] = ['bucket' => $bucket, 'key' => $key, 'encryption' => $serverSideEncryption, 'kms' => $kmsKeyId];
	}

	public function getObject(string $bucket, string $key, int $maxBytes): mixed
	{
		$this->failIf('get');
		$this->readLimits[] = $maxBytes;
		$objectKey = $bucket . '/' . $key;
		if (!array_key_exists($objectKey, $this->objects)) {
			return null;
		}
		if (strlen($this->objects[$objectKey]) > $maxBytes) {
			throw new StorageException('object exceeds maximum read size');
		}
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $this->objects[$objectKey]);
		rewind($stream);
		return $stream;
	}

	public function headObject(string $bucket, string $key): ?int
	{
		$this->failIf('head');
		$objectKey = $bucket . '/' . $key;
		return array_key_exists($objectKey, $this->objects) ? strlen($this->objects[$objectKey]) : null;
	}

	public function deleteObject(string $bucket, string $key): void
	{
		$this->failIf('delete');
		unset($this->objects[$bucket . '/' . $key]);
	}

	public function listObjects(string $bucket, string $prefix, ?string $continuationToken, int $maxKeys): S3ObjectPage
	{
		$this->failIf('list');
		$this->pageSizes[] = $maxKeys;
		$keys = [];
		foreach (array_keys($this->objects) as $objectKey) {
			if (str_starts_with($objectKey, $bucket . '/' . $prefix)) {
				$keys[] = substr($objectKey, strlen($bucket) + 1);
			}
		}
		sort($keys, SORT_STRING);
		$offset = $continuationToken === null ? 0 : (int) $continuationToken;
		$page = array_slice($keys, $offset, $maxKeys);
		$next = $offset + count($page) < count($keys)
			? ($this->repeatContinuationToken ? 'repeat' : (string) ($offset + count($page)))
			: null;
		return new S3ObjectPage($page, $next);
	}

	private function failIf(string $operation): void
	{
		if ($this->failOperation === $operation) {
			throw new StorageException('fake failure includes production secret details');
		}
	}
}
