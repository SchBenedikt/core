<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Federation;

use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

final class FederationTargetGuardTest extends TestCase
{
	public function testNonHttpUserinfoAndUnsafeLiteralTargetsAreRefused(): void
	{
		self::assertSame('invalid_url', $this->reasonFor('ftp://remote.example/actor'));
		self::assertSame('invalid_url', $this->reasonFor('https://user:secret@remote.example/actor'));
		self::assertSame('ssrf_blocked', $this->reasonFor('http://localhost/inbox'));
		self::assertSame('ssrf_blocked', $this->reasonFor('http://127.0.0.1/inbox'));
		self::assertSame('ssrf_blocked', $this->reasonFor('http://[::1]/inbox'));
		self::assertSame('ssrf_blocked', $this->reasonFor('http://[::ffff:127.0.0.1]/inbox'));
	}

	public function testEveryResolvedAddressMustBePublic(): void
	{
		self::assertSame(
			['93.184.216.34'],
			$this->guard(['remote.example' => ['93.184.216.34']])
				->addressesFor('https://remote.example/inbox'),
		);

		self::assertSame(
			'ssrf_blocked',
			$this->reasonFor('https://remote.example/inbox', ['remote.example' => ['10.0.0.5']]),
		);
		self::assertSame(
			'ssrf_blocked',
			$this->reasonFor(
				'https://remote.example/inbox',
				['remote.example' => ['93.184.216.34', '192.168.1.5']],
			),
		);
	}

	public function testAnEmptyResolutionIsNotTreatedAsPublic(): void
	{
		self::assertSame(
			'dns_failed',
			$this->reasonFor('https://remote.example/inbox', ['remote.example' => []]),
		);
	}

	public function testWithoutAResolverOnlyTheUrlAndLiteralAddressesAreChecked(): void
	{
		$guard = new FederationTargetGuard();

		self::assertSame([], $guard->addressesFor('https://remote.example/inbox'));
		self::assertSame('ssrf_blocked', $this->reasonForWithGuard($guard, 'http://192.168.0.1/inbox'));
	}

	/**
	 * @param array<string, list<string>> $addresses
	 */
	private function guard(array $addresses): FederationTargetGuard
	{
		return new FederationTargetGuard(new TestHostResolver($addresses));
	}

	/**
	 * @param array<string, list<string>> $addresses
	 */
	private function reasonFor(string $url, array $addresses = []): string
	{
		return $this->reasonForWithGuard($this->guard($addresses), $url);
	}

	private function reasonForWithGuard(FederationTargetGuard $guard, string $url): string
	{
		try {
			$guard->addressesFor($url);
		} catch (RemoteFetchException $exception) {
			return $exception->reason;
		}

		self::fail('The federation target should have been refused.');
	}
}
