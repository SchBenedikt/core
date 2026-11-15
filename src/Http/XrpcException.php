<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * An XRPC error, which uses `{error, message}` rather than the core's nested
 * `{error: {code, message}}` body. The error name is the AT Protocol string
 * (`InvalidRequest`, `RepoNotFound`, …), not the core's snake_case code.
 */
final class XrpcException extends HttpException
{
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		int $status,
		string $error,
		string $message,
		array $headers = [],
	) {
		parent::__construct($status, $error, $message, $headers);
	}

	/**
	 * @return array{error: string, message: string}
	 */
	public function toArray(): array
	{
		return ['error' => $this->errorCode, 'message' => $this->getMessage()];
	}

	public function toResponse(): Response
	{
		return Response::json($this->toArray(), $this->status, $this->headers);
	}
}
