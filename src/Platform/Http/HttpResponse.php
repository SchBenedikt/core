<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

/**
 * An outbound HTTP response.
 */
final readonly class HttpResponse
{
	/**
	 * @param array<string, string> $headers lower-case header names
	 */
	public function __construct(
		public int $status,
		public array $headers = [],
		public string $body = '',
	) {
	}

	public function isSuccessful(): bool
	{
		return $this->status >= 200 && $this->status < 300;
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}
}
