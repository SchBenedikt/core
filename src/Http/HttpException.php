<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use InvalidArgumentException;
use RuntimeException;

/**
 * An error that already knows how it should be answered.
 *
 * Handlers and middleware throw it; the kernel turns it into the documented
 * JSON error shape. Anything else that escapes becomes a 500.
 */
class HttpException extends RuntimeException
{
	/**
	 * @param array<string, string> $headers
	 * @param array<string, string> $details
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $errorCode,
		string $message,
		public readonly array $headers = [],
		public readonly array $details = [],
	) {
		if ($status < 400 || $status > 599) {
			throw new InvalidArgumentException('An HTTP error status must be between 400 and 599.');
		}

		parent::__construct($message);
	}

	public static function badRequest(string $code, string $message): self
	{
		return new self(400, $code, $message);
	}

	public static function unauthorized(string $message = 'Authentication is required.'): self
	{
		return new self(401, 'authentication_required', $message, ['WWW-Authenticate' => 'Bearer']);
	}

	public static function forbidden(string $message = 'You are not allowed to do that.'): self
	{
		return new self(403, 'forbidden', $message);
	}

	public static function notFound(string $message = 'Not found.'): self
	{
		return new self(404, 'not_found', $message);
	}

	/**
	 * @param list<string> $allowedMethods
	 */
	public static function methodNotAllowed(array $allowedMethods): self
	{
		$allowed = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
		sort($allowed);

		return new self(
			405,
			'method_not_allowed',
			'Method not allowed. Allowed: ' . implode(', ', $allowed) . '.',
			['Allow' => implode(', ', $allowed)],
		);
	}

	public static function validationFailed(array $fields, string $message = 'The request is not valid.'): self
	{
		return new self(422, 'validation_failed', $message, [], $fields);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$error = [
			'code' => $this->errorCode,
			'message' => $this->getMessage(),
		];

		if ($this->details !== []) {
			$error['fields'] = $this->details;
		}

		return ['error' => $error];
	}

	public function toResponse(): Response
	{
		return Response::json($this->toArray(), $this->status, $this->headers);
	}
}
