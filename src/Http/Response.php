<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use JsonException;

/**
 * One outbound HTTP response, independent of the host's framework.
 *
 * Handlers return it; the host adapts it to its own response type without
 * touching the handler.
 */
final readonly class Response
{
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public int $status,
		public array $headers = [],
		public string $body = '',
	) {
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function json(mixed $data, int $status = 200, array $headers = []): self
	{
		$body = json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);

		return new self(
			$status,
			array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
			$body,
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function text(string $body, int $status = 200, array $headers = []): self
	{
		return new self(
			$status,
			array_merge(['Content-Type' => 'text/plain; charset=utf-8'], $headers),
			$body,
		);
	}

	public static function empty(int $status = 204): self
	{
		return new self($status);
	}

	public function header(string $name): ?string
	{
		$needle = strtolower($name);
		foreach ($this->headers as $header => $value) {
			if (strtolower($header) === $needle) {
				return $value;
			}
		}

		return null;
	}

	public function withHeader(string $name, string $value): self
	{
		$headers = $this->headers;
		foreach (array_keys($headers) as $existing) {
			if (strtolower($existing) === strtolower($name)) {
				unset($headers[$existing]);
			}
		}

		$headers[$name] = $value;

		return new self($this->status, $headers, $this->body);
	}

	public function withStatus(int $status): self
	{
		return new self($status, $this->headers, $this->body);
	}

	/**
	 * Emits the response through the SAPI. Used by the CLI front controller.
	 */
	public function send(): void
	{
		http_response_code($this->status);

		foreach ($this->headers as $name => $value) {
			header($name . ': ' . $value);
		}

		if ($this->body !== '') {
			echo $this->body;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'headers' => $this->headers,
			'body' => $this->body,
		];
	}

	/**
	 * @throws JsonException when a caller hands over data that cannot be encoded
	 */
	public function decodedBody(): mixed
	{
		if ($this->body === '') {
			return null;
		}

		return json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
	}
}
