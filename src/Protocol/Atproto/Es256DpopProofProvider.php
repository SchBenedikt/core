<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\RandomIdGenerator;
use InvalidArgumentException;
use JsonException;
use OpenSSLAsymmetricKey;

use function base64_encode;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_sign;
use function ord;
use function parse_url;
use function strlen;
use function str_pad;
use function strtolower;
use function substr;
use function trim;

/**
 * Builds the ES256 DPoP proof required by AT Protocol OAuth.
 *
 * Every proof has a fresh `jti`; the host reuses one P-256 key for a session
 * and never puts the private key in this object after construction. The
 * signature is converted from OpenSSL's DER form to the raw R||S form JWS
 * requires.
 */
final class Es256DpopProofProvider implements DpopProofProvider, DpopKeyThumbprint
{
	private const CURVE = 'prime256v1';

	private const COORDINATE_BYTES = 32;

	private readonly Clock $clock;

	private readonly IdGenerator $ids;

	private readonly OpenSSLAsymmetricKey $key;

	/**
	 * @var array{kty: string, crv: string, x: string, y: string}
	 */
	private readonly array $jwk;

	public function __construct(
		string $privateKeyPem,
		?Clock $clock = null,
		?IdGenerator $ids = null,
	) {
		$key = openssl_pkey_get_private($privateKeyPem);
		if ($key === false) {
			throw new InvalidArgumentException('A DPoP signer needs a valid EC private key.');
		}

		$details = openssl_pkey_get_details($key);
		if ($details === false
			|| ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
			|| ($details['ec']['curve_name'] ?? null) !== self::CURVE
		) {
			throw new InvalidArgumentException('A DPoP signer needs a P-256 EC private key.');
		}

		$this->key = $key;
		$this->clock = $clock ?? new SystemClock();
		$this->ids = $ids ?? new RandomIdGenerator();
		$this->jwk = [
			'kty' => 'EC',
			'crv' => 'P-256',
			'x' => $this->base64Url($this->coordinate($details['ec']['x'] ?? '')),
			'y' => $this->base64Url($this->coordinate($details['ec']['y'] ?? '')),
		];
	}

	/**
	 * RFC 7638 thumbprint of the public JWK. AT Protocol PAR binds the
	 * authorization request to this key with `dpop_jkt`.
	 */
	public function thumbprint(): string
	{
		$canonical = json_encode([
			'crv' => $this->jwk['crv'],
			'kty' => $this->jwk['kty'],
			'x' => $this->jwk['x'],
			'y' => $this->jwk['y'],
		], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		return $this->base64Url(hash('sha256', $canonical, true));
	}

	public function proof(string $method, string $url, ?string $nonce = null): string
	{
		if ($method === '' || trim($method) !== $method) {
			throw new InvalidArgumentException('A DPoP proof needs an HTTP method.');
		}

		if ($nonce !== null && ($nonce === '' || trim($nonce) !== $nonce)) {
			throw new InvalidArgumentException('A DPoP nonce cannot be empty or padded with whitespace.');
		}

		$header = [
			'typ' => 'dpop+jwt',
			'alg' => 'ES256',
			'jwk' => $this->jwk,
		];
		$payload = [
			'jti' => $this->ids->generate(),
			'htm' => strtoupper($method),
			'htu' => $this->normalizedUri($url),
			'iat' => $this->clock->now()->getTimestamp(),
		];

		if ($nonce !== null) {
			$payload['nonce'] = $nonce;
		}

		try {
			$encodedHeader = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR));
			$encodedPayload = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
		} catch (JsonException) {
			throw new InvalidArgumentException('The DPoP proof could not be encoded.');
		}

		$signingInput = $encodedHeader . '.' . $encodedPayload;
		$signature = '';
		if (!openssl_sign($signingInput, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
			throw new InvalidArgumentException('The DPoP proof could not be signed.');
		}

		return $signingInput . '.' . $this->base64Url($this->derToRaw($signature));
	}

	private function normalizedUri(string $url): string
	{
		$parts = parse_url($url);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| (string) $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
		) {
			throw new InvalidArgumentException('A DPoP proof needs an absolute http(s) URL.');
		}

		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new InvalidArgumentException('A DPoP proof needs an absolute http(s) URL.');
		}

		$host = (string) $parts['host'];
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}

		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path = $parts['path'] ?? '/';

		return $scheme . '://' . $host . $port . $path;
	}

	private function coordinate(string $value): string
	{
		if ($value === '' || strlen($value) > self::COORDINATE_BYTES) {
			throw new InvalidArgumentException('The DPoP public key has an invalid coordinate.');
		}

		return str_pad($value, self::COORDINATE_BYTES, "\x00", STR_PAD_LEFT);
	}

	private function derToRaw(string $der): string
	{
		if ($der === '' || ord($der[0]) !== 0x30) {
			throw new InvalidArgumentException('The DPoP signature is not a DER sequence.');
		}

		$offset = 1;
		$length = $this->readLength($der, $offset);
		if ($offset + $length !== strlen($der)) {
			throw new InvalidArgumentException('The DPoP signature has an invalid DER length.');
		}

		$r = $this->readInteger($der, $offset);
		$s = $this->readInteger($der, $offset);
		if ($offset !== strlen($der)) {
			throw new InvalidArgumentException('The DPoP signature has trailing bytes.');
		}

		return $this->padNumber($r) . $this->padNumber($s);
	}

	private function readInteger(string $der, int &$offset): string
	{
		if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
			throw new InvalidArgumentException('The DPoP signature is missing an integer.');
		}

		++$offset;
		$length = $this->readLength($der, $offset);
		if ($length === 0 || $offset + $length > strlen($der)) {
			throw new InvalidArgumentException('The DPoP signature has an invalid integer.');
		}

		$value = substr($der, $offset, $length);
		$offset += $length;

		return $value;
	}

	private function readLength(string $der, int &$offset): int
	{
		if (!isset($der[$offset])) {
			throw new InvalidArgumentException('The DPoP signature is truncated.');
		}

		$first = ord($der[$offset]);
		++$offset;
		if (($first & 0x80) === 0) {
			return $first;
		}

		$count = $first & 0x7f;
		if ($count === 0 || $count > 2 || $offset + $count > strlen($der)) {
			throw new InvalidArgumentException('The DPoP signature has an invalid DER length.');
		}

		$length = 0;
		for ($index = 0; $index < $count; $index++) {
			$length = ($length << 8) | ord($der[$offset]);
			++$offset;
		}

		return $length;
	}

	private function padNumber(string $value): string
	{
		while (strlen($value) > 1 && $value[0] === "\x00") {
			$value = substr($value, 1);
		}

		if (strlen($value) > self::COORDINATE_BYTES) {
			throw new InvalidArgumentException('The DPoP signature number is too large.');
		}

		return str_pad($value, self::COORDINATE_BYTES, "\x00", STR_PAD_LEFT);
	}

	private function base64Url(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
