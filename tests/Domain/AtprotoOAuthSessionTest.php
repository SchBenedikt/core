<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoOAuthSessionTest extends TestCase
{
	public function testItKeepsAnOAuthSessionAndExposesItsScope(): void
	{
		$session = $this->session();

		self::assertSame('did:plc:alice', $session->did);
		self::assertSame('https://auth.example/oauth/token', $session->tokenEndpoint);
		self::assertSame('https://app.example/client.json', $session->clientId);
		self::assertTrue($session->hasScope('atproto'));
		self::assertTrue($session->hasScope('repo:app.bsky.feed.post?action=create'));
		self::assertFalse($session->hasScope('repo:other'));
	}

	public function testRefreshUsesALeeway(): void
	{
		$session = $this->session('2026-11-11T10:02:00Z');
		$now = Time::parse('2026-11-11T10:00:00Z');

		self::assertFalse($session->needsRefresh($now));
		self::assertTrue($session->needsRefresh($now, 120));
		self::assertTrue($session->needsRefresh(Time::parse('2026-11-11T10:01:01Z')));
	}

	public function testRotationPreservesTheSessionIdentity(): void
	{
		$session = $this->session();
		$rotated = $session->withRotatedTokens(
			'new-access',
			'new-refresh',
			'atproto repo:app.bsky.feed.post?action=create',
			Time::parse('2026-11-11T10:05:00Z'),
		);

		self::assertSame($session->did, $rotated->did);
		self::assertSame($session->tokenEndpoint, $rotated->tokenEndpoint);
		self::assertSame($session->clientId, $rotated->clientId);
		self::assertSame('new-access', $rotated->accessToken);
		self::assertSame('new-refresh', $rotated->refreshToken);
	}

	public function testASessionWithoutTheAtprotoScopeIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must contain "atproto"');

		new AtprotoOAuthSession(
			'did:plc:alice',
			'https://auth.example/oauth/token',
			'https://app.example/client.json',
			'access',
			'refresh',
			'repo:app.bsky.feed.post?action=create',
			Time::parse('2026-11-11T10:05:00Z'),
		);
	}

	public function testATokenEndpointWithAQueryIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoOAuthSession(
			'did:plc:alice',
			'https://auth.example/oauth/token?foo=bar',
			'https://app.example/client.json',
			'access',
			'refresh',
			'atproto',
			Time::parse('2026-11-11T10:05:00Z'),
		);
	}

	public function testAnEmptyRefreshTokenIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoOAuthSession(
			'did:plc:alice',
			'https://auth.example/oauth/token',
			'https://app.example/client.json',
			'access',
			'',
			'atproto',
			Time::parse('2026-11-11T10:05:00Z'),
		);
	}

	public function testANegativeRefreshLeewayIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->session()->needsRefresh(Time::parse('2026-11-11T10:00:00Z'), -1);
	}

	private function session(string $expiresAt = '2026-11-11T10:05:00Z'): AtprotoOAuthSession
	{
		return new AtprotoOAuthSession(
			'did:plc:alice',
			'https://auth.example/oauth/token',
			'https://app.example/client.json',
			'access-token',
			'refresh-token',
			'atproto repo:app.bsky.feed.post?action=create',
			Time::parse($expiresAt),
		);
	}
}
