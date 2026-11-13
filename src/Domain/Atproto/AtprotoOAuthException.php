<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use RuntimeException;

/**
 * A stable AT Protocol OAuth client failure.
 *
 * Messages never contain tokens, authorization codes or an authorization
 * server response body; callers may log the code and retryability.
 */
final class AtprotoOAuthException extends RuntimeException
{
	private function __construct(
		public readonly int $status,
		public readonly string $errorCode,
		string $message,
		public readonly bool $retryable = false,
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
		return new self(502, 'dpop_nonce_missing', 'The AT Protocol authorization server did not provide a DPoP nonce.');
	}

	public static function configuration(string $detail): self
	{
		return new self(0, 'oauth_client_not_configured', 'The AT Protocol OAuth client is not configured: ' . $detail);
	}

	public static function unsupported(string $detail): self
	{
		return new self(501, 'oauth_unsupported', 'The AT Protocol authorization server does not support this operation: ' . $detail);
	}

	public static function refused(int $status, string $errorCode, bool $retryable): self
	{
		$safeCode = preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $errorCode) === 1 ? $errorCode : 'oauth_error';

		return new self(
			$status,
			$safeCode,
			'The AT Protocol authorization server refused the request: ' . $safeCode . '.',
			$retryable,
		);
	}
}
