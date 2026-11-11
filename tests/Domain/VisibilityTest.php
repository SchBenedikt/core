<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibilityTest extends TestCase
{
	#[DataProvider('acceptedValues')]
	public function testParsesTheContractValues(string $input, Visibility $expected): void
	{
		self::assertSame($expected, Visibility::fromString($input));
	}

	/**
	 * @return iterable<string, array{string, Visibility}>
	 */
	public static function acceptedValues(): iterable
	{
		yield 'public' => ['public', Visibility::Public];
		yield 'upper case' => ['PUBLIC', Visibility::Public];
		yield 'padded' => [' unlisted ', Visibility::Unlisted];
		yield 'followers' => ['followers', Visibility::Followers];
		yield 'direct' => ['direct', Visibility::Direct];
	}

	public function testRejectsAnUnknownValue(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Visibility::fromString('everyone');
	}

	#[DataProvider('visibilityMatrix')]
	public function testPubliclyVisible(Visibility $visibility, bool $expected): void
	{
		self::assertSame($expected, $visibility->isPubliclyVisible());
	}

	/**
	 * @return iterable<string, array{Visibility, bool}>
	 */
	public static function visibilityMatrix(): iterable
	{
		yield 'public' => [Visibility::Public, true];
		yield 'unlisted' => [Visibility::Unlisted, true];
		yield 'followers' => [Visibility::Followers, false];
		yield 'direct' => [Visibility::Direct, false];
	}
}
