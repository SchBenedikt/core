<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\Repository;

use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DagCborTest extends TestCase
{
	public function testItMatchesTheOfficialCanonicalMapVector(): void
	{
		self::assertSame(
			'a265636f756e74026568656c6c6f65776f726c64',
			bin2hex(DagCbor::encode(['hello' => 'world', 'count' => 2])),
		);
	}

	public function testItRoundTripsCidLinksAndBytes(): void
	{
		$cid = Cid::fromString('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm');
		$value = ['link' => $cid, 'bytes' => new Bytes("\x00\xff")];

		$decoded = DagCbor::decode(DagCbor::encode($value));

		self::assertInstanceOf(Cid::class, $decoded['link']);
		self::assertTrue($cid->equals($decoded['link']));
		self::assertInstanceOf(Bytes::class, $decoded['bytes']);
		self::assertSame("\x00\xff", $decoded['bytes']->value);
	}

	public function testItRejectsNonCanonicalMapOrder(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('a2616201616102'));
	}

	public function testItRejectsDuplicateMapKeys(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('a2616101616102'));
	}

	public function testItRejectsIndefiniteLengths(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('bfff'));
	}

	public function testItRejectsFloats(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('f93c00'));
	}

	public function testItRejectsUnknownTags(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('c101'));
	}

	public function testItRejectsTrailingBytes(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('01ff'));
	}

	public function testItRejectsInvalidUtf8Text(): void
	{
		$this->expectException(InvalidArgumentException::class);

		DagCbor::decode(hex2bin('62c328'));
	}
}
