<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use RuntimeException;

/**
 * A signature that could not be produced or could not be accepted.
 *
 * `reason` is stable and safe to log or to answer a peer with; the message is
 * for humans.
 */
final class SignatureException extends RuntimeException
{
	private function __construct(
		string $message,
		public readonly string $reason,
	) {
		parent::__construct($message);
	}

	public static function malformed(string $detail): self
	{
		return new self('Malformed signature: ' . $detail, 'malformed_signature');
	}

	public static function missingHeader(string $name): self
	{
		return new self('The signature covers the header "' . $name . '", which is missing.', 'missing_header');
	}

	public static function unsupportedAlgorithm(string $algorithm): self
	{
		return new self('Unsupported signature algorithm: ' . $algorithm, 'unsupported_algorithm');
	}

	public static function invalid(): self
	{
		return new self('The signature does not verify.', 'invalid_signature');
	}

	public static function digestMismatch(): self
	{
		return new self('The digest header does not match the request body.', 'digest_mismatch');
	}

	public static function digestNotSigned(): self
	{
		return new self('A request with a body must sign the digest header.', 'digest_not_signed');
	}

	public static function stale(string $date, int $maxAgeSeconds): self
	{
		return new self(
			'The signed date "' . $date . '" is not within ' . $maxAgeSeconds . ' seconds.',
			'stale_date',
		);
	}

	public static function key(string $detail): self
	{
		return new self('Key error: ' . $detail, 'invalid_key');
	}
}
