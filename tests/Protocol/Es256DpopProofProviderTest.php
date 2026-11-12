<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\Atproto\Es256DpopProofProvider;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Es256DpopProofProviderTest extends TestCase
{
	public function testItBuildsAVerifiableDpopProof(): void
	{
		[$privateKey, $publicKey] = $this->keyPair();
		$provider = new Es256DpopProofProvider(
			$privateKey,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$proof = $provider->proof('POST', 'https://auth.example/oauth/token?ignored=1', 'nonce-1');
		$parts = explode('.', $proof);
		self::assertCount(3, $parts);

		$header = json_decode($this->base64UrlDecode($parts[0]), true, 64, JSON_THROW_ON_ERROR);
		$payload = json_decode($this->base64UrlDecode($parts[1]), true, 64, JSON_THROW_ON_ERROR);

		self::assertSame('dpop+jwt', $header['typ']);
		self::assertSame('ES256', $header['alg']);
		self::assertSame('EC', $header['jwk']['kty']);
		self::assertSame('P-256', $header['jwk']['crv']);
		self::assertSame('POST', $payload['htm']);
		self::assertSame('https://auth.example/oauth/token', $payload['htu']);
		self::assertSame('nonce-1', $payload['nonce']);
		self::assertSame(Time::parse('2026-11-11T10:00:00Z')->getTimestamp(), $payload['iat']);
		self::assertSame(1, openssl_verify(
			$parts[0] . '.' . $parts[1],
			$this->rawToDer($this->base64UrlDecode($parts[2])),
			$publicKey,
			OPENSSL_ALGO_SHA256,
		));
	}

	public function testEveryProofHasAFreshJti(): void
	{
		[$privateKey] = $this->keyPair();
		$provider = new Es256DpopProofProvider(
			$privateKey,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$first = $this->payload($provider->proof('POST', 'https://auth.example/oauth/token'));
		$second = $this->payload($provider->proof('POST', 'https://auth.example/oauth/token'));

		self::assertNotSame($first['jti'], $second['jti']);
		self::assertArrayNotHasKey('nonce', $first);
	}

	public function testANonP256KeyIsRejected(): void
	{
		$key = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
			'private_key_bits' => 2048,
		]);
		self::assertNotFalse($key);
		self::assertTrue(openssl_pkey_export($key, $pem));

		$this->expectException(InvalidArgumentException::class);
		new Es256DpopProofProvider($pem);
	}

	public function testAnInvalidMethodIsRejected(): void
	{
		[$privateKey] = $this->keyPair();
		$provider = new Es256DpopProofProvider($privateKey);

		$this->expectException(InvalidArgumentException::class);
		$provider->proof('  ', 'https://auth.example/oauth/token');
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function keyPair(): array
	{
		$key = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);
		self::assertNotFalse($key);
		self::assertTrue(openssl_pkey_export($key, $privateKey));
		$details = openssl_pkey_get_details($key);
		self::assertNotFalse($details);

		return [$privateKey, $details['key']];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(string $proof): array
	{
		$parts = explode('.', $proof);

		return json_decode($this->base64UrlDecode($parts[1]), true, 64, JSON_THROW_ON_ERROR);
	}

	private function base64UrlDecode(string $value): string
	{
		return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
	}

	private function rawToDer(string $raw): string
	{
		self::assertSame(64, strlen($raw));

		$r = $this->derInteger(substr($raw, 0, 32));
		$s = $this->derInteger(substr($raw, 32, 32));
		$sequence = $r . $s;

		return "\x30" . chr(strlen($sequence)) . $sequence;
	}

	private function derInteger(string $raw): string
	{
		$raw = ltrim($raw, "\x00");
		if ($raw === '' || (ord($raw[0]) & 0x80) !== 0) {
			$raw = "\x00" . $raw;
		}

		return "\x02" . chr(strlen($raw)) . $raw;
	}
}
