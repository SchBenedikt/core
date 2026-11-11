<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\StorageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract every {@see BlobStore} must satisfy.
 */
abstract class BlobStoreTestCase extends TestCase
{
	abstract protected function store(): BlobStore;

	public function testPutAndReadRoundTrip(): void
	{
		$store = $this->store();
		$store->put('avatars/alice.png', 'binary-data');

		self::assertSame('binary-data', $store->read('avatars/alice.png'));
		self::assertTrue($store->exists('avatars/alice.png'));
		self::assertSame(11, $store->size('avatars/alice.png'));
		self::assertFalse($store->exists('avatars/bob.png'));
	}

	public function testOverwriteReplacesTheContents(): void
	{
		$store = $this->store();
		$store->put('notes.txt', 'first');
		$store->put('notes.txt', 'second');

		self::assertSame('second', $store->read('notes.txt'));
		self::assertSame(6, $store->size('notes.txt'));
	}

	public function testDeleteRemovesTheBlobAndIsIdempotent(): void
	{
		$store = $this->store();
		$store->put('a.txt', 'x');

		$store->delete('a.txt');
		self::assertFalse($store->exists('a.txt'));

		$store->delete('a.txt');
		$store->delete('never-existed.txt');
	}

	public function testReadingAMissingBlobThrows(): void
	{
		$this->expectException(StorageException::class);

		$this->store()->read('missing.bin');
	}

	public function testSizingAMissingBlobThrows(): void
	{
		$this->expectException(StorageException::class);

		$this->store()->size('missing.bin');
	}

	public function testListIsSortedAndFilteredByPrefix(): void
	{
		$store = $this->store();
		$store->put('b/2.txt', 'x');
		$store->put('a/2.txt', 'x');
		$store->put('a/1.txt', 'x');
		$store->put('c.txt', 'x');

		self::assertSame(['a/1.txt', 'a/2.txt', 'b/2.txt', 'c.txt'], $store->list());
		self::assertSame(['a/1.txt', 'a/2.txt'], $store->list('a'));
		self::assertSame(['b/2.txt'], $store->list('b/'));
		self::assertSame([], $store->list('nope'));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidPaths(): iterable
	{
		yield 'empty' => [''];
		yield 'absolute' => ['/etc/passwd'];
		yield 'parent' => ['../secret'];
		yield 'nested parent' => ['a/../../secret'];
		yield 'current directory' => ['./a'];
		yield 'backslash' => ['a\\b'];
		yield 'double slash' => ['a//b'];
		yield 'trailing slash' => ['a/'];
		yield 'null byte' => ["a\0b"];
	}

	#[DataProvider('invalidPaths')]
	public function testRejectsInvalidPathsOnWrite(string $path): void
	{
		$this->expectException(StorageException::class);

		$this->store()->put($path, 'x');
	}

	#[DataProvider('invalidPaths')]
	public function testRejectsInvalidPathsOnEveryOperation(string $path): void
	{
		$store = $this->store();

		$operations = [
			static fn () => $store->exists($path),
			static fn () => $store->read($path),
			static fn () => $store->delete($path),
			static fn () => $store->size($path),
		];

		foreach ($operations as $operation) {
			try {
				$operation();
				self::fail('Expected a StorageException for the path "' . $path . '".');
			} catch (StorageException) {
				self::assertTrue(true);
			}
		}
	}
}
