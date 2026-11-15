<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use RuntimeException;

/**
 * A failed PDS operation that already knows its protocol error name.
 *
 * The XRPC adapter turns these into com.atproto's `{error, message}` shape;
 * the service itself has no dependency on the HTTP layer.
 */
final class PdsError extends RuntimeException
{
	public function __construct(
		public readonly int $status,
		public readonly string $error,
		string $message,
	) {
		parent::__construct($message);
	}

	public static function invalid(string $message): self
	{
		return new self(400, 'InvalidRequest', $message);
	}

	public static function repoNotFound(string $message = 'The repository does not exist.'): self
	{
		return new self(400, 'RepoNotFound', $message);
	}

	public static function recordNotFound(string $message = 'The record does not exist.'): self
	{
		return new self(404, 'RecordNotFound', $message);
	}

	public static function recordExists(): self
	{
		return new self(400, 'RecordAlreadyExists', 'A record with that collection and rkey already exists.');
	}

	public static function forbidden(string $message = 'The authenticated account does not own this repository.'): self
	{
		return new self(403, 'Forbidden', $message);
	}

	public static function notEnabled(): self
	{
		return new self(503, 'PdsNotEnabled', 'The native PDS is not enabled on this service.');
	}

	public static function internal(string $message = 'The repository operation failed.'): self
	{
		return new self(500, 'InternalServerError', $message);
	}
}
