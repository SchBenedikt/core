<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RsaKeyPairTest extends TestCase
{
	private static RsaKeyPair $key;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	public function testGeneratesAPemPair(): void
	{
		self::assertStringContainsString('PRIVATE KEY', self::$key->privateKeyPem());
		self::assertStringContainsString('PUBLIC KEY', self::$key->publicKeyPem());
	}

	public function testSignsAndVerifies(): void
	{
		$signature = self::$key->sign('hello');

		self::assertNotSame('', $signature);
		self::assertTrue(self::$key->verify('hello', $signature));
	}

	public function testADifferentPayloadDoesNotVerify(): void
	{
		$signature = self::$key->sign('hello');

		self::assertFalse(self::$key->verify('hello!', $signature));
	}

	public function testAnotherKeyDoesNotVerify(): void
	{
		$other = RsaKeyPair::generate();

		self::assertFalse($other->verify('hello', self::$key->sign('hello')));
	}

	public function testRoundTripsThroughPems(): void
	{
		$restored = RsaKeyPair::fromPems(self::$key->privateKeyPem(), self::$key->publicKeyPem());

		self::assertTrue($restored->verify('hello', $restored->sign('hello')));
	}

	public function testRefusesAWeakKey(): void
	{
		$this->expectException(InvalidArgumentException::class);

		RsaKeyPair::generate(1024);
	}

	public function testRefusesSomethingThatIsNotAKey(): void
	{
		$this->expectException(\AlohaSocial\Core\Protocol\ActivityPub\SignatureException::class);

		RsaKeyPair::fromPems('not a key', 'not a key');
	}
}
