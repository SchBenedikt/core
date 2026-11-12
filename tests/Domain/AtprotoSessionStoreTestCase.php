<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionStore;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * Every host-owned session store must preserve one session per local user and
 * replace it atomically after a refresh.
 */
abstract class AtprotoSessionStoreTestCase extends TestCase
{
	abstract protected function store(): AtprotoSessionStore;

	public function testAnUnknownUserHasNoSession(): void
	{
		self::assertNull($this->store()->find('alice'));
	}

	public function testSaveAndFindRoundTrip(): void
	{
		$store = $this->store();
		$session = $this->session();

		$store->save('alice', $session);

		self::assertSame($session, $store->find('alice'));
	}

	public function testSavingAReplacementKeepsOnlyTheRotatedSession(): void
	{
		$store = $this->store();
		$old = $this->session();
		$new = $this->session('new-access', 'new-refresh');

		$store->save('alice', $old);
		$store->save('alice', $new);

		self::assertSame($new, $store->find('alice'));
		self::assertNotSame($old, $store->find('alice'));
	}

	public function testSessionsAreIsolatedPerLocalUser(): void
	{
		$store = $this->store();
		$alice = $this->session();
		$bob = $this->session('bob-access', 'bob-refresh', 'did:plc:bob');

		$store->save('alice', $alice);
		$store->save('bob', $bob);

		self::assertSame($alice, $store->find('alice'));
		self::assertSame($bob, $store->find('bob'));
	}

	private function session(
		string $accessToken = 'access-token',
		string $refreshToken = 'refresh-token',
		string $did = 'did:plc:alice',
	): AtprotoOAuthSession {
		return new AtprotoOAuthSession(
			$did,
			'https://auth.example/oauth/token',
			'https://app.example/client.json',
			$accessToken,
			$refreshToken,
			'atproto',
			Time::parse('2026-11-11T10:05:00Z'),
		);
	}
}
