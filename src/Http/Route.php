<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use InvalidArgumentException;

/**
 * One entry of the HTTP contract.
 *
 * `requiresAuth` and `rateLimit` are metadata, not enforcement: the host
 * enforces them, the contract documents them, and both hosts are checked
 * against the same list.
 */
final readonly class Route
{
	public string $name;

	public string $method;

	public string $path;

	/** @var class-string<Handler> */
	public string $handler;

	public bool $requiresAuth;

	public ?string $rateLimit;

	public ?string $summary;

	/**
	 * @param class-string<Handler> $handler
	 * @param string|null           $rateLimit e.g. "30/900" — 30 requests per 900 seconds
	 */
	public function __construct(
		string $name,
		string $method,
		string $path,
		string $handler,
		bool $requiresAuth = true,
		?string $rateLimit = null,
		?string $summary = null,
	) {
		$method = strtoupper(trim($method));
		if (preg_match('/^[A-Z]+$/', $method) !== 1) {
			throw new InvalidArgumentException('Invalid HTTP method: ' . $method);
		}

		if ($path === '' || $path[0] !== '/') {
			throw new InvalidArgumentException('A route path must start with "/": ' . $path);
		}

		if (str_contains($path, '?')) {
			throw new InvalidArgumentException('A route path must not contain a query string: ' . $path);
		}

		if ($name === '') {
			throw new InvalidArgumentException('A route needs a name.');
		}

		if ($rateLimit !== null && preg_match('#^\d+/[1-9]\d*$#', $rateLimit) !== 1) {
			throw new InvalidArgumentException('A rate limit is "<requests>/<seconds>": ' . $rateLimit);
		}

		$this->name = $name;
		$this->method = $method;
		$this->path = $path;
		$this->handler = $handler;
		$this->requiresAuth = $requiresAuth;
		$this->rateLimit = $rateLimit;
		$this->summary = $summary;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'method' => $this->method,
			'path' => $this->path,
			'handler' => $this->handler,
			'requires_auth' => $this->requiresAuth,
			'rate_limit' => $this->rateLimit,
			'summary' => $this->summary,
		];
	}
}
