<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\Repository;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CidTest extends TestCase
{
	public function testItMatchesTheOfficialDagCborCidVector(): void
	{
		$cbor = hex2bin('a265636f756e74026568656c6c6f65776f726c64');

		self::assertSame(
			'bafyreieypur6lewa2r6yuy62xvucxqspwkhmcrmv45bcy6au42j76rzdeq',
			Cid::forData($cbor)->toString(),
		);
	}

	public function testItRoundTripsItsBase32Form(): void
	{
		$cid = Cid::fromString('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm');

		self::assertSame(
			'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm',
			Cid::fromBytes($cid->bytes())->toString(),
		);
	}

	public function testItRefusesANonBase32Prefix(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Cid::fromString('zafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm');
	}

	public function testItRefusesATruncatedDigest(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$cid = Cid::fromString('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm');
		Cid::fromBytes(substr($cid->bytes(), 0, -1));
	}

	public function testItRefusesAnotherCodec(): void
	{
		$this->expectException(InvalidArgumentException::class);

		// CIDv1, raw codec (0x55), SHA-256, 32 bytes.
		Cid::fromString('bafkreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm');
	}
}
