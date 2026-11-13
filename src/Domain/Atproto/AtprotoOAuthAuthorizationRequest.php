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
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

/**
 * The local state of one started AT Protocol authorization flow.
 *
 * The verifier, state and DPoP nonce are credentials for the flow and belong
 * in the host's short-lived flow store, never in a log or a response. The
 * object contains everything the callback needs; the host can persist it
 * opaquely between the two requests.
 */
final readonly class AtprotoOAuthAuthorizationRequest
{
	public const MAX_STATE_LENGTH = 512;

	public const MAX_VERIFIER_LENGTH = 512;

	public const MAX_NONCE_LENGTH = 1024;

	public function __construct(
		public string $state,
		public string $codeVerifier,
		public string $did,
		public string $pdsUrl,
		public AtprotoOAuthServer $server,
		public string $authorizationUrl,
		public string $dpopNonce,
	) {
		if (preg_match('/^[A-Za-z0-9._~-]{16,512}$/', $state) !== 1 || strlen($state) > self::MAX_STATE_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs a valid state.');
		}

		if (preg_match('/^[A-Za-z0-9._~-]{43,512}$/', $codeVerifier) !== 1
			|| strlen($codeVerifier) > self::MAX_VERIFIER_LENGTH
		) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs a valid PKCE verifier.');
		}

		if (preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs a valid DID.');
		}

		if ($pdsUrl === '' || trim($pdsUrl) !== $pdsUrl) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs a PDS URL.');
		}

		$pds = parse_url($pdsUrl);
		if ($pds === false
			|| !isset($pds['scheme'], $pds['host'])
			|| (string) $pds['host'] === ''
			|| !in_array(strtolower($pds['scheme']), ['http', 'https'], true)
			|| isset($pds['user'])
			|| isset($pds['pass'])
			|| isset($pds['query'])
			|| isset($pds['fragment'])
		) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs an absolute PDS URL.');
		}

		$authorization = parse_url($authorizationUrl);
		if ($authorization === false
			|| !isset($authorization['scheme'], $authorization['host'], $authorization['query'])
			|| (string) $authorization['host'] === ''
			|| !in_array(strtolower($authorization['scheme']), ['http', 'https'], true)
			|| isset($authorization['user'])
			|| isset($authorization['pass'])
			|| isset($authorization['fragment'])
		) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs an authorization URL.');
		}

		if ($dpopNonce === '' || trim($dpopNonce) !== $dpopNonce || strlen($dpopNonce) > self::MAX_NONCE_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol authorization request needs a DPoP nonce.');
		}
	}
}
