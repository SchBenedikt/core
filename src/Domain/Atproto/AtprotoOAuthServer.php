<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

use function in_array;
use function parse_url;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

/**
 * The authorization-server metadata needed to start and finish an AT Protocol flow.
 *
 * The issuer is an origin, not an endpoint with a path. The endpoints are
 * absolute http(s) URLs without credentials, query or fragment; the client
 * rejects a metadata document that violates these rules before any request is
 * sent.
 */
final readonly class AtprotoOAuthServer
{
	public string $issuer;

	public string $authorizationEndpoint;

	public string $tokenEndpoint;

	public string $parEndpoint;

	public ?string $revocationEndpoint;

	public function __construct(
		string $issuer,
		string $authorizationEndpoint,
		string $tokenEndpoint,
		string $parEndpoint,
		?string $revocationEndpoint = null,
	) {
		$issuer = rtrim($issuer, '/');
		self::assertIssuer($issuer);
		self::assertEndpoint($authorizationEndpoint, 'authorization endpoint');
		self::assertEndpoint($tokenEndpoint, 'token endpoint');
		self::assertEndpoint($parEndpoint, 'pushed authorization request endpoint');
		if ($revocationEndpoint !== null) {
			self::assertEndpoint($revocationEndpoint, 'revocation endpoint');
		}

		$this->issuer = $issuer;
		$this->authorizationEndpoint = $authorizationEndpoint;
		$this->tokenEndpoint = $tokenEndpoint;
		$this->parEndpoint = $parEndpoint;
		$this->revocationEndpoint = $revocationEndpoint;
	}

	private static function assertIssuer(string $issuer): void
	{
		$parts = parse_url($issuer);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| (string) $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['path'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
			|| str_contains($issuer, '?')
			|| str_contains($issuer, '#')
		) {
			throw new InvalidArgumentException('The AT Protocol authorization server issuer must be an origin.');
		}
	}

	private static function assertEndpoint(string $url, string $label): void
	{
		if ($url === '' || trim($url) !== $url) {
			throw new InvalidArgumentException('The AT Protocol ' . $label . ' is invalid.');
		}

		$parts = parse_url($url);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| (string) $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		) {
			throw new InvalidArgumentException('The AT Protocol ' . $label . ' must be an absolute http(s) URL.');
		}
	}
}
