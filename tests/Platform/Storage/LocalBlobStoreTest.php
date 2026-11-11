<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Storage;

use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LocalBlobStoreTest extends BlobStoreTestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/alohasocial-blob-' . bin2hex(random_bytes(8));
	}

	protected function tearDown(): void
	{
		$this->remove($this->root);
	}

	protected function store(): BlobStore
	{
		return new LocalBlobStore($this->root);
	}

	public function testWritesLandBelowTheRoot(): void
	{
		$store = $this->store();
		$store->put('a/b/c.txt', 'hello');

		self::assertFileExists($this->root . '/a/b/c.txt');
		self::assertSame('hello', file_get_contents($this->root . '/a/b/c.txt'));
	}

	public function testListingAMissingRootIsEmpty(): void
	{
		self::assertSame([], $this->store()->list());
	}

	public function testAnEmptyRootIsRefused(): void
	{
		$this->expectException(\AlohaSocial\Core\Platform\Storage\StorageException::class);

		new LocalBlobStore('');
	}

	public function testTheStoreSurvivesAnOverwriteOfTheSamePath(): void
	{
		$store = $this->store();
		$store->put('x.bin', '1');
		$store->put('x.bin', '22');

		self::assertSame('22', $store->read('x.bin'));
		self::assertCount(1, $store->list());
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);

		/** @var SplFileInfo $entry */
		foreach ($iterator as $entry) {
			if ($entry->isDir()) {
				@rmdir($entry->getPathname());
			} else {
				@unlink($entry->getPathname());
			}
		}

		@rmdir($path);
	}
}
