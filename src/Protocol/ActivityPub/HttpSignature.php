<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function in_array;
use function is_string;

/**
 * HTTP Signatures (draft-cavage-http-signatures-12) over RSA-SHA256.
 *
 * Signed headers are the request target, the host, the date and — whenever
 * there is a body — the digest. The signature covers those header lines
 * verbatim, which is why the request target and the host are taken as
 * parameters instead of being re-derived from a URL: a proxy may rewrite one
 * and not the other, and the verifier has to see exactly what was signed.
 *
 * `verify()` refuses a body whose digest is not signed: a signature over the
 * headers alone would let anybody replace the payload of a replayed delivery.
 */
final class HttpSignature
{
	public const ALGORITHM = 'rsa-sha256';

	public const HEADERS = '(request-target) host date';

	public const HEADERS_WITH_BODY = '(request-target) host date digest';

	private function __construct()
	{
	}

	/**
	 * @return array<string, string> the headers to send
	 *
	 * @throws SignatureException
	 */
	public static function sign(
		RsaKeyPair $key,
		string $keyId,
		string $method,
		string $requestTarget,
		string $host,
		string $body,
		DateTimeImmutable $now,
	): array {
		if ($keyId === '' || str_contains($keyId, '"')) {
			throw SignatureException::malformed('The key id must be non-empty and must not contain a quote.');
		}

		if ($host === '' || str_contains($host, "\n") || str_contains($host, "\r")) {
			throw SignatureException::malformed('The host header is empty or contains a line break.');
		}

		if ($requestTarget === '' || $requestTarget[0] !== '/') {
			throw SignatureException::malformed('The request target must start with "/".');
		}

		$date = Time::httpDate($now);
		$names = ['(request-target)', 'host', 'date'];
		$values = [
			'(request-target)' => strtolower($method) . ' ' . $requestTarget,
			'host' => $host,
			'date' => $date,
		];
		$headers = [
			'Host' => $host,
			'Date' => $date,
		];

		if ($body !== '') {
			$digest = self::digest($body);
			$names[] = 'digest';
			$values['digest'] = $digest;
			$headers['Digest'] = $digest;
		}

		$signature = base64_encode($key->sign(self::signingString($names, $values)));

		$headers['Signature'] = sprintf(
			'keyId="%s",algorithm="%s",headers="%s",signature="%s"',
			$keyId,
			self::ALGORITHM,
			implode(' ', $names),
			$signature,
		);

		return $headers;
	}

	/**
	 * Verifies a signed request.
	 *
	 * @param array<string, string> $headers lower-case header names, including `signature`
	 *
	 * @throws SignatureException with a stable reason when the request is not acceptable
	 */
	public static function verify(
		string $publicKeyPem,
		string $method,
		string $requestTarget,
		array $headers,
		string $body,
		int $maxAgeSeconds,
		DateTimeImmutable $now,
	): void {
		$signatureHeader = $headers['signature'] ?? null;
		if (!is_string($signatureHeader) || trim($signatureHeader) === '') {
			throw SignatureException::missingHeader('signature');
		}

		$parameters = self::parseSignatureHeader($signatureHeader);

		$keyId = $parameters['keyid'] ?? '';
		if ($keyId === '') {
			throw SignatureException::malformed('The signature header has no keyId.');
		}

		$algorithm = strtolower($parameters['algorithm'] ?? self::ALGORITHM);
		if ($algorithm !== self::ALGORITHM) {
			throw SignatureException::unsupportedAlgorithm($algorithm);
		}

		$headerList = preg_split('/\s+/', trim($parameters['headers'] ?? self::HEADERS)) ?: [];
		$headerList = array_values(array_filter(
			array_map(static fn (string $name): string => strtolower($name), $headerList),
			static fn (string $name): bool => $name !== '',
		));

		// These are the request-line and freshness guarantees this verifier
		// promises. A signature over only the request target would be valid
		// forever and could be replayed against another host, so an unsigned
		// required header is a refusal even when the RSA signature itself is
		// valid.
		foreach (['(request-target)', 'host', 'date'] as $required) {
			if (!in_array($required, $headerList, true)) {
				throw SignatureException::missingHeader($required);
			}
		}

		$values = [];
		foreach ($headerList as $name) {
			if ($name === '(request-target)') {
				$values[$name] = strtolower($method) . ' ' . $requestTarget;

				continue;
			}

			$value = $headers[$name] ?? null;
			if (!is_string($value)) {
				throw SignatureException::missingHeader($name);
			}

			$values[$name] = $value;
		}

		$decodedSignature = base64_decode($parameters['signature'] ?? '', true);
		if ($decodedSignature === false || $decodedSignature === '') {
			throw SignatureException::malformed('The signature is not valid base64.');
		}

		if (openssl_verify(
			self::signingString($headerList, $values),
			$decodedSignature,
			$publicKeyPem,
			OPENSSL_ALGO_SHA256,
		) !== 1) {
			throw SignatureException::invalid();
		}

		if ($body !== '') {
			if (!in_array('digest', $headerList, true)) {
				throw SignatureException::digestNotSigned();
			}

			$digest = $headers['digest'] ?? null;
			if (!is_string($digest) || !hash_equals(self::digest($body), $digest)) {
				throw SignatureException::digestMismatch();
			}
		}

		if (in_array('date', $headerList, true)) {
			$date = $headers['date'] ?? '';
			$timestamp = strtotime($date);
			if ($timestamp === false) {
				throw SignatureException::malformed('The date header cannot be parsed.');
			}

			if (abs($now->getTimestamp() - $timestamp) > $maxAgeSeconds) {
				throw SignatureException::stale($date, $maxAgeSeconds);
			}
		}
	}

	/**
	 * @return array<string, string> lower-case parameter names
	 */
	public static function parseSignatureHeader(string $header): array
	{
		$header = trim($header);
		if (str_starts_with($header, 'Signature ')) {
			$header = substr($header, 10);
		}

		preg_match_all('/([a-zA-Z]+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);

		$parameters = [];
		foreach ($matches as $match) {
			$parameters[strtolower($match[1])] = $match[2];
		}

		return $parameters;
	}

	public static function digest(string $body): string
	{
		return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
	}

	/**
	 * @param list<string>          $names
	 * @param array<string, string> $values
	 */
	private static function signingString(array $names, array $values): string
	{
		$lines = [];
		foreach ($names as $name) {
			if (!array_key_exists($name, $values)) {
				throw SignatureException::missingHeader($name);
			}

			$lines[] = $name . ': ' . $values[$name];
		}

		return implode("\n", $lines);
	}
}
