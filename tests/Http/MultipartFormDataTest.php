<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\MultipartFormData;
use AlohaSocial\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class MultipartFormDataTest extends TestCase
{
	public function testParsesAFileAndAnOptionalField(): void
	{
		$body = $this->body('a-boundary', [
			['name' => 'alt_text', 'contents' => 'A description'],
			['name' => 'file', 'filename' => 'cat.png', 'type' => 'image/png', 'contents' => "\x00\x01binary"],
		]);

		$form = MultipartFormData::fromRequest($this->request('a-boundary', $body));

		self::assertSame('A description', $form->field('alt_text'));
		self::assertNull($form->field('missing'));

		$file = $form->file('file');
		self::assertNotNull($file);
		self::assertSame('file', $file->fieldName);
		self::assertSame('cat.png', $file->filename);
		self::assertSame('image/png', $file->contentType);
		self::assertSame("\x00\x01binary", $file->contents);
	}

	public function testAcceptsAQuotedBoundary(): void
	{
		$body = $this->body('quoted-boundary', [
			['name' => 'file', 'filename' => 'x.bin', 'type' => 'application/octet-stream', 'contents' => 'x'],
		]);

		$form = MultipartFormData::fromRequest(new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data; boundary="quoted-boundary"'],
			body: $body,
		));

		self::assertSame('x', $form->file('file')?->contents);
	}

	public function testARequestWithoutABoundaryIsRejected(): void
	{
		$exception = $this->invalid(fn (): MultipartFormData => MultipartFormData::fromRequest(new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data'],
			body: 'x',
		)));

		self::assertSame(400, $exception->status);
		self::assertSame('invalid_multipart', $exception->errorCode);
	}

	public function testAMalformedPartIsRejected(): void
	{
		$exception = $this->invalid(fn (): MultipartFormData => MultipartFormData::fromRequest(
			$this->request('b', "--b\r\nX-No-Separator\r\n"),
		));

		self::assertSame('invalid_multipart', $exception->errorCode);
	}

	public function testAMissingClosingBoundaryIsRejected(): void
	{
		$body = "--b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.png\"\r\n\r\nx";

		$exception = $this->invalid(fn (): MultipartFormData => MultipartFormData::fromRequest($this->request('b', $body)));

		self::assertSame('invalid_multipart', $exception->errorCode);
	}

	public function testARepeatedFileFieldIsRejected(): void
	{
		$body = $this->body('b', [
			['name' => 'file', 'filename' => 'a.png', 'contents' => 'a'],
			['name' => 'file', 'filename' => 'b.png', 'contents' => 'b'],
		]);

		$exception = $this->invalid(fn (): MultipartFormData => MultipartFormData::fromRequest($this->request('b', $body)));

		self::assertSame('invalid_multipart', $exception->errorCode);
	}

	/**
	 * @param list<array{name: string, filename?: string, type?: string, contents: string}> $parts
	 */
	private function body(string $boundary, array $parts): string
	{
		$body = '';
		foreach ($parts as $part) {
			$body .= '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $part['name'] . '"';
			if (isset($part['filename'])) {
				$body .= '; filename="' . $part['filename'] . '"';
			}
			$body .= "\r\n";
			if (isset($part['type'])) {
				$body .= 'Content-Type: ' . $part['type'] . "\r\n";
			}
			$body .= "\r\n" . $part['contents'] . "\r\n";
		}

		return $body . '--' . $boundary . "--\r\n";
	}

	private function request(string $boundary, string $body): Request
	{
		return new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			body: $body,
		);
	}

	private function invalid(callable $operation): HttpException
	{
		try {
			$operation();
		} catch (HttpException $exception) {
			return $exception;
		}

		self::fail('Expected an HttpException.');
	}
}
