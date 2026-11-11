<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Protocol\ActivityPub\SignatureException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HttpSignatureTest extends TestCase
{
	private const KEY_ID = 'https://social.example/@alice#main-key';

	private const HOST = 'remote.example';

	private const TARGET = '/inbox?shared=true';

	private static RsaKeyPair $key;

	private DateTimeImmutable $now;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->now = Time::parse('2026-11-11T10:00:00Z');
	}

	public function testSignsAndVerifiesARequestWithABody(): void
	{
		$body = '{"type":"Create"}';
		$signed = $this->sign($body);

		self::assertArrayHasKey('Digest', $signed);
		self::assertSame(HttpSignature::digest($body), $signed['Digest']);

		HttpSignature::verify(
			self::$key->publicKeyPem(),
			'POST',
			self::TARGET,
			$this->incoming($signed),
			$body,
			300,
			$this->now,
		);

		self::assertTrue(true);
	}

	public function testSignsAndVerifiesARequestWithoutABody(): void
	{
		$signed = $this->sign('');

		self::assertArrayNotHasKey('Digest', $signed);

		HttpSignature::verify(
			self::$key->publicKeyPem(),
			'POST',
			self::TARGET,
			$this->incoming($signed),
			'',
			300,
			$this->now,
		);

		self::assertTrue(true);
	}

	public function testTheSignatureHeaderNamesKeyAlgorithmAndHeaders(): void
	{
		$signed = $this->sign('body');
		$parameters = HttpSignature::parseSignatureHeader($signed['Signature']);

		self::assertSame(self::KEY_ID, $parameters['keyid']);
		self::assertSame(HttpSignature::ALGORITHM, $parameters['algorithm']);
		self::assertSame(HttpSignature::HEADERS_WITH_BODY, $parameters['headers']);
		self::assertNotSame('', $parameters['signature']);
	}

	public function testAChangedBodyIsDetected(): void
	{
		$signed = $this->sign('original');

		$this->assertRefusal(
			'digest_mismatch',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$this->incoming($signed),
				'tampered',
				300,
				$this->now,
			),
		);
	}

	public function testAChangedHeaderIsDetected(): void
	{
		$signed = $this->sign('body');
		$incoming = $this->incoming($signed);
		$incoming['host'] = 'attacker.example';

		$this->assertRefusal(
			'invalid_signature',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$incoming,
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testAnotherKeyDoesNotVerify(): void
	{
		$signed = $this->sign('body');

		$this->assertRefusal(
			'invalid_signature',
			fn () => HttpSignature::verify(
				RsaKeyPair::generate()->publicKeyPem(),
				'POST',
				self::TARGET,
				$this->incoming($signed),
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testAStaleDateIsRefused(): void
	{
		$signed = HttpSignature::sign(
			self::$key,
			self::KEY_ID,
			'POST',
			self::TARGET,
			self::HOST,
			'body',
			$this->now->modify('-1000 seconds'),
		);

		$this->assertRefusal(
			'stale_date',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$this->incoming($signed),
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testAMissingSignedHeaderIsRefused(): void
	{
		$signed = $this->sign('body');
		$incoming = $this->incoming($signed);
		unset($incoming['digest']);

		$this->assertRefusal(
			'missing_header',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$incoming,
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testABodyMustSignItsDigest(): void
	{
		$signed = $this->sign('');

		$this->assertRefusal(
			'digest_not_signed',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$this->incoming($signed),
				'a body nobody signed',
				300,
				$this->now,
			),
		);
	}

	public function testAnUnsupportedAlgorithmIsRefused(): void
	{
		$signed = $this->sign('body');
		$incoming = $this->incoming($signed);
		$incoming['signature'] = str_replace(
			'algorithm="' . HttpSignature::ALGORITHM . '"',
			'algorithm="hmac-sha256"',
			$incoming['signature'],
		);

		$this->assertRefusal(
			'unsupported_algorithm',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$incoming,
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testAMalformedSignatureHeaderIsRefused(): void
	{
		$signed = $this->sign('body');
		$incoming = $this->incoming($signed);
		$incoming['signature'] = 'nonsense';

		$this->assertRefusal(
			'malformed_signature',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				$incoming,
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testAMissingSignatureHeaderIsRefused(): void
	{
		$this->assertRefusal(
			'missing_header',
			fn () => HttpSignature::verify(
				self::$key->publicKeyPem(),
				'POST',
				self::TARGET,
				[],
				'body',
				300,
				$this->now,
			),
		);
	}

	public function testRefusesToSignWithoutARequestTarget(): void
	{
		$this->expectException(SignatureException::class);

		HttpSignature::sign(self::$key, self::KEY_ID, 'POST', 'inbox', self::HOST, 'body', $this->now);
	}

	public function testRefusesToSignAKeyIdWithAQuote(): void
	{
		$this->expectException(SignatureException::class);

		HttpSignature::sign(self::$key, 'https://x/#"k', 'POST', '/inbox', self::HOST, 'body', $this->now);
	}

	public function testDigestIsStable(): void
	{
		self::assertSame(HttpSignature::digest('abc'), HttpSignature::digest('abc'));
		self::assertNotSame(HttpSignature::digest('abc'), HttpSignature::digest('abd'));
		self::assertStringStartsWith('SHA-256=', HttpSignature::digest('abc'));
	}

	/**
	 * @return array<string, string>
	 */
	private function sign(string $body): array
	{
		return HttpSignature::sign(
			self::$key,
			self::KEY_ID,
			'POST',
			self::TARGET,
			self::HOST,
			$body,
			$this->now,
		);
	}

	/**
	 * @param array<string, string> $signed
	 *
	 * @return array<string, string>
	 */
	private function incoming(array $signed): array
	{
		return array_change_key_case($signed, CASE_LOWER);
	}

	private function assertRefusal(string $reason, callable $operation): void
	{
		try {
			$operation();
			self::fail('Expected a SignatureException with reason ' . $reason . '.');
		} catch (SignatureException $exception) {
			self::assertSame($reason, $exception->reason);
		}
	}
}
