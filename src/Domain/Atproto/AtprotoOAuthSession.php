<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;
use function parse_url;
use function preg_match;
use function preg_split;
use function strlen;
use function strtolower;
use function trim;

/**
 * One AT Protocol OAuth session.
 *
 * Tokens are opaque credentials and stay in the host's secret store. This
 * value object only carries the protocol data needed to refresh them; it is
 * never rendered into an API response or written to a log line.
 */
final readonly class AtprotoOAuthSession
{
	public const MAX_TOKEN_LENGTH = 8192;

	public const MAX_CLIENT_ID_LENGTH = 512;

	public const MAX_ENDPOINT_LENGTH = 2048;

	public const MAX_SCOPE_LENGTH = 1024;

	public function __construct(
		public string $did,
		public string $tokenEndpoint,
		public string $clientId,
		public string $accessToken,
		public string $refreshToken,
		public string $scope,
		public DateTimeImmutable $expiresAt,
	) {
		if (preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
			throw new InvalidArgumentException('An AT Protocol OAuth session needs a valid DID.');
		}

		$this->assertEndpoint($tokenEndpoint);

		if ($clientId === ''
			|| trim($clientId) !== $clientId
			|| strlen($clientId) > self::MAX_CLIENT_ID_LENGTH
		) {
			throw new InvalidArgumentException('An AT Protocol OAuth session needs a valid client id.');
		}

		$clientParts = parse_url($clientId);
		if ($clientParts === false
			|| !isset($clientParts['scheme'], $clientParts['host'])
			|| !in_array(strtolower($clientParts['scheme']), ['http', 'https'], true)
			|| (string) $clientParts['host'] === ''
			|| isset($clientParts['user'])
			|| isset($clientParts['pass'])
		) {
			throw new InvalidArgumentException('The AT Protocol OAuth client id must be an absolute http(s) URL.');
		}

		$this->assertToken($accessToken, 'access');
		$this->assertToken($refreshToken, 'refresh');

		if ($scope === '' || trim($scope) !== $scope || strlen($scope) > self::MAX_SCOPE_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol OAuth session needs a valid scope.');
		}

		if (!$this->hasAtprotoScope($scope)) {
			throw new InvalidArgumentException('An AT Protocol OAuth session scope must contain "atproto".');
		}
	}

	/**
	 * A small clock-skew allowance avoids sending a request with a token that
	 * expires while the request is in flight.
	 */
	public function needsRefresh(DateTimeImmutable $now, int $leewaySeconds = 60): bool
	{
		if ($leewaySeconds < 0) {
			throw new InvalidArgumentException('The token refresh leeway cannot be negative.');
		}

		return $this->expiresAt->getTimestamp() <= $now->getTimestamp() + $leewaySeconds;
	}

	public function withRotatedTokens(
		string $accessToken,
		string $refreshToken,
		string $scope,
		DateTimeImmutable $expiresAt,
	): self {
		return new self(
			did: $this->did,
			tokenEndpoint: $this->tokenEndpoint,
			clientId: $this->clientId,
			accessToken: $accessToken,
			refreshToken: $refreshToken,
			scope: $scope,
			expiresAt: $expiresAt,
		);
	}

	public function hasScope(string $scope): bool
	{
		return in_array($scope, preg_split('/\s+/', $this->scope) ?: [], true);
	}

	private function assertEndpoint(string $endpoint): void
	{
		if ($endpoint === '' || trim($endpoint) !== $endpoint || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol OAuth session needs a valid token endpoint.');
		}

		$parts = parse_url($endpoint);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
			|| (string) $parts['host'] === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		) {
			throw new InvalidArgumentException(
				'The AT Protocol OAuth token endpoint must be an absolute http(s) URL without a query or fragment.',
			);
		}
	}

	private function assertToken(string $token, string $kind): void
	{
		if ($token === '' || trim($token) !== $token || strlen($token) > self::MAX_TOKEN_LENGTH) {
			throw new InvalidArgumentException(
				'An AT Protocol OAuth session needs a valid ' . $kind . ' token.',
			);
		}
	}

	private function hasAtprotoScope(string $scope): bool
	{
		foreach (preg_split('/\s+/', $scope) ?: [] as $value) {
			if ($value === 'atproto') {
				return true;
			}
		}

		return false;
	}
}
