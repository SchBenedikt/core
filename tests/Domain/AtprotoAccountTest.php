<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoAccountTest extends TestCase
{
	public function testItAcceptsAPdsAccount(): void
	{
		$account = new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example/', 'token');

		self::assertSame('did:plc:alice', $account->did);
		self::assertSame('https://pds.example/', $account->pdsUrl);
	}

	public function testItRejectsAnInvalidDid(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoAccount('alice', 'alice.test', 'https://pds.example', 'token');
	}

	public function testItRejectsAnInvalidHandle(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoAccount('did:plc:alice', 'Alice', 'https://pds.example', 'token');
	}

	public function testItRejectsANonHttpPds(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoAccount('did:plc:alice', 'alice.test', 'ftp://pds.example', 'token');
	}

	public function testItRejectsAnEmptyToken(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', ' ');
	}
}
