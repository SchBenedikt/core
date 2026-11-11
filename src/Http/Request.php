<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use JsonException;

/**
 * One inbound HTTP request, independent of the host's framework.
 *
 * The Nextcloud host builds it from `IRequest`, the standalone host from
 * Symfony's `Request`, tests build it directly. Paths are normalised on
 * construction so routing never has to care about a trailing slash.
 */
final readonly class Request
{
	public string $method;

	public string $path;

	/**
	 * @var array<string, mixed>
	 */
	public array $query;

	/**
	 * @var array<string, string> lower-case header names
	 */
	public array $headers;

	public string $body;

	/**
	 * @var array<string, mixed> route parameters and other request-scoped data
	 */
	public array $attributes;

	public ?string $remoteAddress;

	public ?string $userId;

	/**
	 * @param array<string, mixed>  $query      query parameters, values cast to string
	 * @param array<string, string> $headers    header names in any case
	 * @param array<string, mixed>  $attributes
	 */
	public function __construct(
		string $method,
		string $path,
		array $query = [],
		array $headers = [],
		string $body = '',
		array $attributes = [],
		?string $remoteAddress = null,
		?string $userId = null,
	) {
		$this->method = strtoupper(trim($method));
		$this->path = self::normalizePath($path);
		$this->query = self::normalizeQuery($query);
		$this->headers = self::normalizeHeaders($headers);
		$this->body = $body;
		$this->attributes = $attributes;
		$this->remoteAddress = $remoteAddress;
		$this->userId = $userId;
	}

	/**
	 * The request as PHP exposes it.
	 *
	 * The host is responsible for authenticating the caller and passing the
	 * user id; this method only reads what the SAPI provides.
	 */
	public static function fromGlobals(): self
	{
		$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
		$queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

		$query = [];
		if ($queryString !== '') {
			parse_str($queryString, $query);
		}

		$headers = function_exists('getallheaders') ? getallheaders() : [];
		if (!is_array($headers) || $headers === []) {
			$headers = self::headersFromServer($_SERVER);
		}

		$body = file_get_contents('php://input');

		return new self(
			(string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			(string) (parse_url($uri, PHP_URL_PATH) ?? '/'),
			$query,
			$headers,
			$body === false ? '' : $body,
			[],
			isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
			null,
		);
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}

	public function queryParam(string $name, ?string $default = null): ?string
	{
		if (!array_key_exists($name, $this->query)) {
			return $default;
		}

		$value = $this->query[$name];
		if (is_array($value)) {
			return $default;
		}

		return (string) $value;
	}

	/**
	 * An integer query parameter inside a range.
	 *
	 * A value that is not a number or is outside the range becomes the
	 * default rather than an error: a limit is a preference, not a command.
	 */
	public function queryInt(string $name, int $default, int $min, int $max): int
	{
		$value = $this->queryParam($name);
		if ($value === null || preg_match('/^-?\d+$/', trim($value)) !== 1) {
			return $default;
		}

		$number = (int) trim($value);
		if ($number < $min || $number > $max) {
			return $default;
		}

		return $number;
	}

	public function attribute(string $name, mixed $default = null): mixed
	{
		return $this->attributes[$name] ?? $default;
	}

	public function withAttribute(string $name, mixed $value): self
	{
		$attributes = $this->attributes;
		$attributes[$name] = $value;

		return $this->with(attributes: $attributes);
	}

	public function withUserId(?string $userId): self
	{
		return $this->with(userId: $userId);
	}

	/**
	 * The decoded JSON body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HttpException 400 when the body is not a JSON object
	 */
	public function json(): array
	{
		if (trim($this->body) === '') {
			return [];
		}

		try {
			$decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw HttpException::badRequest('invalid_json', 'The request body is not valid JSON.');
		}

		if (!is_array($decoded)) {
			throw HttpException::badRequest('invalid_json', 'The request body must be a JSON object.');
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * @param array<string, mixed>|null  $query
	 * @param array<string, string>|null $headers
	 * @param array<string, mixed>|null  $attributes
	 */
	private function with(
		?array $query = null,
		?array $headers = null,
		?array $attributes = null,
		?string $userId = null,
	): self {
		$next = new self(
			$this->method,
			$this->path,
			$query ?? $this->query,
			$headers ?? $this->headers,
			$this->body,
			$attributes ?? $this->attributes,
			$this->remoteAddress,
			$userId ?? $this->userId,
		);

		return $next;
	}

	private static function normalizePath(string $path): string
	{
		$onlyPath = parse_url($path, PHP_URL_PATH);
		if (is_string($onlyPath) && $onlyPath !== '') {
			$path = $onlyPath;
		}

		if ($path === '' || $path[0] !== '/') {
			$path = '/' . $path;
		}

		if ($path !== '/') {
			$path = rtrim($path, '/');
		}

		return $path === '' ? '/' : $path;
	}

	/**
	 * @param array<array-key, mixed> $query
	 *
	 * @return array<string, mixed>
	 */
	private static function normalizeQuery(array $query): array
	{
		$normalized = [];
		foreach ($query as $key => $value) {
			$name = (string) $key;
			if (is_array($value)) {
				$normalized[$name] = $value;

				continue;
			}

			if (is_bool($value)) {
				$normalized[$name] = $value ? '1' : '0';

				continue;
			}

			$normalized[$name] = (string) $value;
		}

		return $normalized;
	}

	/**
	 * @param array<array-key, mixed> $headers
	 *
	 * @return array<string, string>
	 */
	private static function normalizeHeaders(array $headers): array
	{
		$normalized = [];
		foreach ($headers as $name => $value) {
			if (is_array($value)) {
				$value = implode(', ', array_map(static fn ($entry): string => (string) $entry, $value));
			}

			$normalized[strtolower((string) $name)] = trim((string) $value);
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $server
	 *
	 * @return array<string, string>
	 */
	private static function headersFromServer(array $server): array
	{
		$headers = [];
		foreach ($server as $name => $value) {
			if (!str_starts_with((string) $name, 'HTTP_') || !is_string($value)) {
				continue;
			}

			$header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $name, 5)))));
			$headers[$header] = $value;
		}

		if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
			$headers['Content-Type'] = $server['CONTENT_TYPE'];
		}

		return $headers;
	}
}
