<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

use function explode;
use function is_string;
use function preg_match;
use function strlen;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * A small, dependency-free multipart/form-data parser.
 *
 * The HTTP abstraction carries the raw body, so the core can accept a file
 * upload without depending on PHP's `$_FILES` globals. The parser is
 * deliberately strict: a missing boundary, a malformed part or too many parts
 * is a clean `invalid_multipart` response, not a partially parsed upload.
 */
final readonly class MultipartFormData
{
	private const MAX_PARTS = 8;

	private const MAX_FIELD_BYTES = 16_384;

	/**
	 * @param array<string, string> $fields
	 * @param array<string, UploadedFile> $files
	 */
	public function __construct(
		public array $fields,
		public array $files,
	) {
	}

	/**
	 * @throws HttpException when the request is not valid multipart/form-data
	 */
	public static function fromRequest(Request $request): self
	{
		$contentType = $request->header('content-type');
		if ($contentType === null || preg_match(
			'#^multipart/form-data\s*;\s*boundary=(?:"([^"]+)"|([^;]+))#i',
			$contentType,
			$matches,
		) !== 1) {
			throw HttpException::badRequest('invalid_multipart', 'A multipart boundary is required.');
		}

		$boundary = $matches[1] !== '' ? $matches[1] : trim($matches[2]);
		if ($boundary === '' || strlen($boundary) > 200) {
			throw HttpException::badRequest('invalid_multipart', 'The multipart boundary is invalid.');
		}

		if ($request->body === '') {
			throw HttpException::badRequest('invalid_multipart', 'The multipart body is empty.');
		}

		$segments = explode('--' . $boundary, $request->body);
		$closing = end($segments);
		if (!is_string($closing) || !str_starts_with(ltrim($closing), '--')) {
			throw HttpException::badRequest('invalid_multipart', 'The multipart body has no closing boundary.');
		}

		$fields = [];
		$files = [];
		$parts = 0;

		foreach ($segments as $index => $segment) {
			if ($index === 0 || str_starts_with($segment, '--')) {
				continue;
			}

			$parts++;
			if ($parts > self::MAX_PARTS) {
				throw HttpException::badRequest('invalid_multipart', 'The form has too many parts.');
			}

			$segment = preg_replace('/^\r?\n/', '', $segment, 1);
			$segment = preg_replace('/\r?\n$/', '', $segment, 1);
			if (!is_string($segment)) {
				throw HttpException::badRequest('invalid_multipart', 'The multipart body is malformed.');
			}

			$separator = strpos($segment, "\r\n\r\n");
			$separatorLength = 4;
			if ($separator === false) {
				$separator = strpos($segment, "\n\n");
				$separatorLength = 2;
			}

			if ($separator === false) {
				throw HttpException::badRequest('invalid_multipart', 'A multipart part has no body separator.');
			}

			$rawHeaders = substr($segment, 0, $separator);
			$contents = substr($segment, $separator + $separatorLength);
			$headers = self::headers($rawHeaders);

			$disposition = $headers['content-disposition'] ?? null;
			if ($disposition === null || preg_match(
				'#^form-data\s*;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?#i',
				$disposition,
				$dispositionMatches,
			) !== 1) {
				throw HttpException::badRequest('invalid_multipart', 'A multipart part has no name.');
			}

			$name = $dispositionMatches[1];
			$filename = $dispositionMatches[2] ?? '';

			if ($filename !== '') {
				if (isset($files[$name])) {
					throw HttpException::badRequest('invalid_multipart', 'A file field was sent twice.');
				}

				$files[$name] = new UploadedFile(
					fieldName: $name,
					filename: $filename,
					contentType: $headers['content-type'] ?? 'application/octet-stream',
					contents: $contents,
				);
				continue;
			}

			if (isset($fields[$name])) {
				throw HttpException::badRequest('invalid_multipart', 'A field was sent twice.');
			}

			if (strlen($contents) > self::MAX_FIELD_BYTES) {
				throw HttpException::badRequest('invalid_multipart', 'A form field is too large.');
			}

			$fields[$name] = $contents;
		}

		if ($parts === 0) {
			throw HttpException::badRequest('invalid_multipart', 'The multipart body has no parts.');
		}

		return new self($fields, $files);
	}

	public function field(string $name): ?string
	{
		return $this->fields[$name] ?? null;
	}

	public function file(string $name): ?UploadedFile
	{
		return $this->files[$name] ?? null;
	}

	/**
	 * @return array<string, string> lower-case header names
	 */
	private static function headers(string $rawHeaders): array
	{
		$headers = [];
		foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
			if (trim($line) === '') {
				continue;
			}

			$separator = strpos($line, ':');
			if ($separator === false) {
				throw HttpException::badRequest('invalid_multipart', 'A multipart header is malformed.');
			}

			$name = strtolower(trim(substr($line, 0, $separator)));
			$value = trim(substr($line, $separator + 1));
			if ($name === '' || $value === '') {
				throw HttpException::badRequest('invalid_multipart', 'A multipart header is empty.');
			}

			$headers[$name] = $value;
		}

		return $headers;
	}
}
