<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefreshException;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefresher;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoSessionStore;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Atproto\AtprotoSessionService;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class AtprotoSessionServiceTest extends TestCase
{
	public function testAValidSessionIsReturnedWithoutRefreshing(): void
	{
		$session = $this->session('2026-11-11T10:10:00Z');
		$store = new InMemoryAtprotoSessionStore(['alice' => $session]);
		$refresher = new RecordingSessionRefresher($this->session('2026-11-11T10:10:00Z'));
		$service = new AtprotoSessionService(
			$store,
			$refresher,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		self::assertSame($session, $service->current('alice'));
		self::assertSame(0, $refresher->calls);
	}

	public function testAnExpiringSessionIsRotatedAndSaved(): void
	{
		$old = $this->session('2026-11-11T10:00:30Z');
		$new = $this->session('2026-11-11T10:05:00Z', 'new-access', 'new-refresh');
		$store = new InMemoryAtprotoSessionStore(['alice' => $old]);
		$refresher = new RecordingSessionRefresher($new);
		$service = new AtprotoSessionService(
			$store,
			$refresher,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		self::assertSame($new, $service->current('alice'));
		self::assertSame(1, $refresher->calls);
		self::assertSame($new, $store->find('alice'));
	}

	public function testAnUnknownUserHasNoSession(): void
	{
		$service = new AtprotoSessionService(
			new InMemoryAtprotoSessionStore(),
			new RecordingSessionRefresher($this->session()),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		self::assertNull($service->current('alice'));
	}

	public function testARefreshFailureLeavesTheStoredSessionUntouched(): void
	{
		$old = $this->session('2026-11-11T10:00:00Z');
		$store = new InMemoryAtprotoSessionStore(['alice' => $old]);
		$service = new AtprotoSessionService(
			$store,
			new FailingSessionRefresher(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$service->current('alice');
			self::fail('The service should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_grant', $exception->errorCode);
			self::assertSame($old, $store->find('alice'));
		}
	}

	public function testARefreshThatChangesTheDidIsRejected(): void
	{
		$old = $this->session('2026-11-11T10:00:00Z');
		$other = $this->session('2026-11-11T10:05:00Z', 'new-access', 'new-refresh', 'did:plc:mallory');
		$store = new InMemoryAtprotoSessionStore(['alice' => $old]);
		$service = new AtprotoSessionService(
			$store,
			new RecordingSessionRefresher($other),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$this->expectException(AtprotoSessionRefreshException::class);

		try {
			$service->current('alice');
		} finally {
			self::assertSame($old, $store->find('alice'));
		}
	}

	private function session(
		string $expiresAt = '2026-11-11T10:05:00Z',
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
			Time::parse($expiresAt),
		);
	}
}

final class RecordingSessionRefresher implements AtprotoSessionRefresher
{
	public int $calls = 0;

	public function __construct(private readonly AtprotoOAuthSession $session)
	{
	}

	public function refresh(AtprotoOAuthSession $session): AtprotoOAuthSession
	{
		++$this->calls;

		return $this->session;
	}
}

final class FailingSessionRefresher implements AtprotoSessionRefresher
{
	public function refresh(AtprotoOAuthSession $session): AtprotoOAuthSession
	{
		throw AtprotoSessionRefreshException::refused(400, 'invalid_grant', false);
	}
}
