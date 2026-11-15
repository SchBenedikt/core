<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\Repository;

use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\Varint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CarTest extends TestCase
{
	private const CAR_HEX = '3aa265726f6f747381d82a582500017112203484748bdedf2735a9c853506029c74ce72d3a51f16b4f483c99bb9fc37743fc6776657273696f6e01f001017112203484748bdedf2735a9c853506029c74ce72d3a51f16b4f483c99bb9fc37743fca2616583a4616b58186170702e62736b792e666565642e6c696b652f336b6768696170006174f66176d82a58250001711220f96a83d2f2dfbc68dda3d0a37506696d32801ec490eb6c18e95f2cb41770f124a4616b4a706f73742f336b61626361700e6174f66176d82a58250001711220112da4efd1a03e0ea6b1a68c05e677f3b6a34b79432fc7313d0f6890f5e7a618a4616b436465666170156174f66176d82a58250001711220e79deda100b6f065ccaff33e9a2dd746598cd5fb611d75a956b010628f25ed53616cf6';

	public function testItDecodesAndReencodesTheOfficialCar(): void
	{
		$car = Car::decode(hex2bin(self::CAR_HEX));

		self::assertSame(
			'bafyreibuqr2ixxw7e422tsctkbqctr2m44wtuuprnnhuqpezxop4g52d7q',
			$car->root->toString(),
		);
		self::assertSame(self::CAR_HEX, bin2hex($car->encode()));
	}

	public function testItRefusesATamperedBlock(): void
	{
		$this->expectException(UnexpectedValueException::class);

		$bytes = hex2bin(self::CAR_HEX);
		$bytes[strlen($bytes) - 1] = "\x00";

		Car::decode($bytes);
	}

	public function testItRefusesADuplicateBlock(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$bytes = hex2bin(self::CAR_HEX);
		$offset = 0;
		$headerLength = Varint::decode($bytes, $offset);
		$offset += $headerLength;
		$blockLength = Varint::decode($bytes, $offset);
		$block = substr($bytes, $offset, $blockLength);

		Car::decode($bytes . Varint::encode($blockLength) . $block);
	}

	public function testItRefusesATruncatedArchive(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Car::decode(substr(hex2bin(self::CAR_HEX), 0, -8));
	}

	public function testItRequiresTheRootBlock(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$root = Cid::fromString('bafyreibuqr2ixxw7e422tsctkbqctr2m44wtuuprnnhuqpezxop4g52d7q');
		new Car($root, []);
	}
}
