<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use PHPUnit\Framework\TestCase;

final class InMemoryActorSearchTest extends TestCase
{
	public function testSearchIsCaseInsensitiveAndStable(): void
	{
		$directory = new InMemoryActorDirectory(['carol', 'Alice', 'bob']);

		self::assertSame(['Alice'], $directory->search('ali', 10, 0));
		self::assertSame([], $directory->search('', 10, 0));
		self::assertSame([], $directory->search('nobody', 10, 0));
	}

	public function testSearchHonoursTheAllowedSetAndOffset(): void
	{
		$directory = new InMemoryActorDirectory(['anna', 'alice', 'bob']);

		self::assertSame(['alice', 'anna'], $directory->search('a', 10, 0));
		self::assertSame(['anna'], $directory->search('a', 10, 1));
		self::assertSame([], $directory->search('a', 10, 0, ['bob']));
		self::assertSame([], $directory->search('a', 10, 0, []));
	}

	public function testHasStillChecksTheExactDirectoryEntry(): void
	{
		$directory = new InMemoryActorDirectory(['alice']);

		self::assertTrue($directory->has('alice'));
		self::assertFalse($directory->has('Alice'));
	}
}
