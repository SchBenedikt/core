<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\S3BlobStore;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Tests\Support\FakeS3CompatibleClient;
use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function stream_get_contents;

final class S3BlobStoreTest extends BlobStoreTestCase
{
	private FakeS3CompatibleClient $client;

	protected function store(): BlobStore
	{
		$this->client = new FakeS3CompatibleClient();
		return new S3BlobStore($this->client, 'media-test', 'core/media', listPageSize: 2);
	}

	public function testStreamOperationsRespectPrefixAndServerSideEncryption(): void
	{
		$client = new FakeS3CompatibleClient();
		$store = new S3BlobStore($client, 'media-test', 'core/media', 'aws:kms', 'kms/test-key');
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, 'streamed bytes');
		rewind($stream);

		$store->putStream('posts/one.bin', $stream, 14);
		fclose($stream);
		self::assertSame('streamed bytes', $store->read('posts/one.bin'));
		self::assertSame([
			['bucket' => 'media-test', 'key' => 'core/media/posts/one.bin', 'encryption' => 'aws:kms', 'kms' => 'kms/test-key'],
		], $client->puts);
		self::assertSame(['posts/one.bin'], $store->list('posts'));
		self::assertSame([S3BlobStore::DEFAULT_MAX_OBJECT_BYTES], $client->readLimits);
		self::assertNotEmpty($client->pageSizes);
	}

	public function testReadAndWriteRespectConfiguredObjectLimit(): void
	{
		$client = new FakeS3CompatibleClient();
		$store = new S3BlobStore($client, 'media-test', maxObjectBytes: 3);
		$store->put('small.bin', '123');
		$this->expectException(StorageException::class);
		$store->put('large.bin', '1234');
	}

	public function testReadStreamRejectsAnObjectAboveTheConfiguredLimit(): void
	{
		$client = new FakeS3CompatibleClient();
		$client->objects['media-test/large.bin'] = '1234';
		$store = new S3BlobStore($client, 'media-test', maxObjectBytes: 3);
		$this->expectException(StorageException::class);
		$store->readStream('large.bin');
	}

	public function testReadStreamReturnsCallerOwnedReadableStream(): void
	{
		$client = new FakeS3CompatibleClient();
		$client->objects['media-test/path.bin'] = 'abc';
		$store = new S3BlobStore($client, 'media-test');
		$stream = $store->readStream('path.bin');
		self::assertSame('abc', stream_get_contents($stream));
		fclose($stream);
	}

	public function testListingFollowsContinuationTokensAndRejectsClientKeysOutsidePrefix(): void
	{
		$client = new FakeS3CompatibleClient();
		$store = new S3BlobStore($client, 'media-test', 'core/media', listPageSize: 1);
		$client->objects = [
			'media-test/core/media/c.txt' => 'c',
			'media-test/core/media/a.txt' => 'a',
			'media-test/core/media/b.txt' => 'b',
			'media-test/core/media-old/secret.txt' => 'outside',
		];
		self::assertSame(['a.txt', 'b.txt', 'c.txt'], $store->list());
	}

	public function testListingTreatsPrefixAsAPathSegment(): void
	{
		$client = new FakeS3CompatibleClient();
		$client->objects = [
			'media-test/core/media/posts/one.bin' => 'inside',
			'media-test/core/media/posts-old/secret.bin' => 'outside',
		];
		$store = new S3BlobStore($client, 'media-test', 'core/media');
		self::assertSame(['posts/one.bin'], $store->list('posts'));
	}

	public function testListingStopsWhenTheClientRepeatsAContinuationToken(): void
	{
		$client = new FakeS3CompatibleClient();
		$client->repeatContinuationToken = true;
		$client->objects = [
			'media-test/a.bin' => 'a',
			'media-test/b.bin' => 'b',
		];
		$store = new S3BlobStore($client, 'media-test', listPageSize: 1);
		$this->expectException(StorageException::class);
		$store->list();
	}

	public function testClientFailuresBecomeStorageExceptionsWithoutLeakingDetails(): void
	{
		$client = new FakeS3CompatibleClient();
		$client->failOperation = 'put';
		$store = new S3BlobStore($client, 'media-test');
		try {
			$store->put('failed.bin', 'secret bytes');
			self::fail('Expected the storage failure to be mapped.');
		} catch (StorageException $exception) {
			self::assertStringNotContainsString('fake failure', $exception->getMessage());
		}
	}
}
