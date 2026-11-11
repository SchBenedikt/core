<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Security;

use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Security\SecretBoxException;
use PHPUnit\Framework\TestCase;

final class OpenSslSecretBoxTest extends TestCase
{
	private string $key;

	protected function setUp(): void
	{
		$this->key = OpenSslSecretBox::generateKey();
	}

	public function testSealsAndOpens(): void
	{
		$box = OpenSslSecretBox::fromBase64Key($this->key);

		$sealed = $box->seal('-----BEGIN PRIVATE KEY-----');

		self::assertNotSame('-----BEGIN PRIVATE KEY-----', $sealed);
		self::assertSame('-----BEGIN PRIVATE KEY-----', $box->open($sealed));
	}

	public function testEverySealUsesAFreshNonce(): void
	{
		$box = OpenSslSecretBox::fromBase64Key($this->key);

		self::assertNotSame($box->seal('same'), $box->seal('same'));
	}

	public function testGeneratedKeysAreThirtyTwoBytes(): void
	{
		$raw = base64_decode($this->key, true);

		self::assertNotFalse($raw);
		self::assertSame(32, strlen($raw));
	}

	public function testATamperedPayloadIsRefused(): void
	{
		$box = OpenSslSecretBox::fromBase64Key($this->key);
		$sealed = $box->seal('secret');

		$raw = base64_decode($sealed, true);
		self::assertIsString($raw);
		$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

		$this->expectException(SecretBoxException::class);

		$box->open(base64_encode($raw));
	}

	public function testAnotherKeyCannotOpenThePayload(): void
	{
		$sealed = OpenSslSecretBox::fromBase64Key($this->key)->seal('secret');

		$this->expectException(SecretBoxException::class);

		OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey())->open($sealed);
	}

	public function testRefusesAnUnknownVersion(): void
	{
		$box = OpenSslSecretBox::fromBase64Key($this->key);
		$raw = base64_decode($box->seal('secret'), true);
		self::assertIsString($raw);
		$raw[0] = "\x09";

		$this->expectException(SecretBoxException::class);

		$box->open(base64_encode($raw));
	}

	public function testRefusesSomethingThatIsNotASealedPayload(): void
	{
		$this->expectException(SecretBoxException::class);

		OpenSslSecretBox::fromBase64Key($this->key)->open('not base64 at all!!');
	}

	public function testRefusesAKeyOfTheWrongLength(): void
	{
		$this->expectException(SecretBoxException::class);

		OpenSslSecretBox::fromBase64Key(base64_encode(random_bytes(16)));
	}
}
