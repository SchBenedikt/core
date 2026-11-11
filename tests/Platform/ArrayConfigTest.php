<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform;

use AlohaSocial\Core\Platform\Config\ArrayConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayConfigTest extends TestCase
{
	public function testReturnsTheDefaultWhenTheKeyIsMissing(): void
	{
		$config = new ArrayConfig();

		self::assertNull($config->get('social', 'missing'));
		self::assertSame('fallback', $config->get('social', 'missing', 'fallback'));
	}

	public function testReadsAndWritesValues(): void
	{
		$config = new ArrayConfig(['social' => ['instance.name' => 'Example']]);

		self::assertSame('Example', $config->get('social', 'instance.name'));
		self::assertTrue($config->has('social', 'instance.name'));

		$config->set('social', 'instance.name', 'Renamed');
		self::assertSame('Renamed', $config->get('social', 'instance.name'));

		$config->delete('social', 'instance.name');
		self::assertFalse($config->has('social', 'instance.name'));
	}

	public function testAllIsSortedAndScoped(): void
	{
		$config = new ArrayConfig([
			'social' => ['b' => '2', 'a' => '1'],
			'other' => ['a' => 'x'],
		]);

		self::assertSame(['a' => '1', 'b' => '2'], $config->all('social'));
	}

	#[DataProvider('booleanValues')]
	public function testBooleanParsing(?string $stored, bool $expected): void
	{
		$config = new ArrayConfig($stored === null ? [] : ['social' => ['flag' => $stored]]);

		self::assertSame($expected, $config->getBool('social', 'flag', false));
	}

	/**
	 * @return iterable<string, array{?string, bool}>
	 */
	public static function booleanValues(): iterable
	{
		yield 'missing' => [null, false];
		yield 'one' => ['1', true];
		yield 'true' => ['true', true];
		yield 'YES' => ['YES', true];
		yield 'on' => ['on', true];
		yield 'zero' => ['0', false];
		yield 'false' => ['false', false];
		yield 'empty' => ['', false];
	}

	public function testAnUnparseableBooleanFallsBackToTheDefault(): void
	{
		$config = new ArrayConfig(['social' => ['flag' => 'sometimes']]);

		self::assertTrue($config->getBool('social', 'flag', true));
	}

	#[DataProvider('integerValues')]
	public function testIntegerParsing(?string $stored, int $expected): void
	{
		$config = new ArrayConfig($stored === null ? [] : ['social' => ['limit' => $stored]]);

		self::assertSame($expected, $config->getInt('social', 'limit', 7));
	}

	/**
	 * @return iterable<string, array{?string, int}>
	 */
	public static function integerValues(): iterable
	{
		yield 'missing' => [null, 7];
		yield 'plain' => ['42', 42];
		yield 'negative' => ['-3', -3];
		yield 'padded' => [' 12 ', 12];
		yield 'text' => ['many', 7];
		yield 'float' => ['1.5', 7];
	}

	public function testRejectsAnEmptyAppId(): void
	{
		$this->expectException(InvalidArgumentException::class);

		(new ArrayConfig())->get('', 'key');
	}

	public function testRejectsAnEmptyKey(): void
	{
		$this->expectException(InvalidArgumentException::class);

		(new ArrayConfig())->get('social', '');
	}

	public function testRejectsNonStringValues(): void
	{
		$this->expectException(InvalidArgumentException::class);

		/** @phpstan-ignore-next-line intentionally wrong for the test */
		new ArrayConfig(['social' => ['flag' => true]]);
	}
}
