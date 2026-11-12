<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use RuntimeException;

use function preg_match;

/**
 * A refresh exchange did not produce a usable session.
 *
 * Messages contain only a local explanation or the OAuth error code. Never
 * include tokens or the authorization server's response body.
 */
final class AtprotoSessionRefreshException extends RuntimeException
{
	public function __construct(
		public readonly int $status,
		public readonly string $errorCode,
		string $message,
		public readonly bool $retryable,
	) {
		parent::__construct($message);
	}

	public static function transportFailure(): self
	{
		return new self(0, 'transport_error', 'The AT Protocol authorization server could not be reached.', true);
	}

	public static function invalidResponse(string $detail): self
	{
		return new self(502, 'invalid_response', 'The AT Protocol authorization server returned an invalid response: ' . $detail, true);
	}

	public static function missingNonce(): self
	{
		return new self(
			502,
			'dpop_nonce_missing',
			'The AT Protocol authorization server did not provide a DPoP nonce.',
			false,
		);
	}

	public static function refused(int $status, string $errorCode, bool $retryable): self
	{
		$safeCode = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $errorCode) === 1 ? $errorCode : 'oauth_error';

		return new self($status, $safeCode, 'The AT Protocol authorization server refused the refresh: ' . $safeCode . '.', $retryable);
	}
}
