<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Http\Response;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
	public function testJsonSetsTheContentTypeAndEncodes(): void
	{
		$response = Response::json(['ok' => true]);

		self::assertSame(200, $response->status);
		self::assertSame('application/json; charset=utf-8', $response->header('content-type'));
		self::assertSame('{"ok":true}', $response->body);
		self::assertSame(['ok' => true], $response->decodedBody());
	}

	public function testJsonKeepsSlashesAndUnicodeReadable(): void
	{
		$response = Response::json(['url' => 'https://x/y', 'text' => 'Grüße']);

		self::assertStringContainsString('https://x/y', $response->body);
		self::assertStringContainsString('Grüße', $response->body);
	}

	public function testJsonCarriesACustomStatusAndHeaders(): void
	{
		$response = Response::json(['error' => 'x'], 422, ['Location' => '/x']);

		self::assertSame(422, $response->status);
		self::assertSame('/x', $response->header('Location'));
	}

	public function testTextSetsAPlainContentType(): void
	{
		$response = Response::text('hello');

		self::assertSame('text/plain; charset=utf-8', $response->header('content-type'));
		self::assertSame('hello', $response->body);
	}

	public function testEmptyHasNoBody(): void
	{
		$response = Response::empty();

		self::assertSame(204, $response->status);
		self::assertSame('', $response->body);
		self::assertNull($response->decodedBody());
	}

	public function testHeaderLookupIsCaseInsensitive(): void
	{
		$response = new Response(200, ['Content-Type' => 'text/html']);

		self::assertSame('text/html', $response->header('content-type'));
		self::assertNull($response->header('x-missing'));
	}

	public function testWithHeaderReplacesAnExistingHeaderWhateverItsCase(): void
	{
		$response = (new Response(200, ['Content-Type' => 'text/html']))->withHeader('content-type', 'text/csv');

		self::assertSame('text/csv', $response->header('content-type'));
		self::assertCount(1, $response->headers);
	}

	public function testWithStatusKeepsTheBodyAndHeaders(): void
	{
		$response = (new Response(200, ['X' => '1'], 'body'))->withStatus(503);

		self::assertSame(503, $response->status);
		self::assertSame('body', $response->body);
		self::assertSame('1', $response->header('X'));
	}

	public function testStreamResponseProducesChunksLazilyAndPreservesProducerWhenDecorated(): void
	{
		$started = false;
		$response = Response::stream(static function () use (&$started): iterable {
			$started = true;
			yield "event: update\ndata: {\"id\":\"1\"}\n\n";
			yield ": keepalive\n\n";
		}, ['Content-Type' => 'text/event-stream']);

		self::assertFalse($started);
		self::assertSame('text/event-stream', $response->header('content-type'));
		$decorated = $response->withHeader('Cache-Control', 'no-cache')->withStatus(206);
		self::assertSame(206, $decorated->status);
		self::assertSame('no-cache', $decorated->header('Cache-Control'));
		self::assertSame($response->streamBody, $decorated->streamBody);
		self::assertSame([
			"event: update\ndata: {\"id\":\"1\"}\n\n",
			": keepalive\n\n",
		], iterator_to_array($response->chunks(), false));
		self::assertTrue($started);
	}

	public function testStreamResponseRejectsNonStringChunks(): void
	{
		$response = Response::stream(static function (): iterable {
			yield ['not', 'a', 'string'];
		});

		$this->expectException(\UnexpectedValueException::class);
		iterator_to_array($response->chunks(), false);
	}

	public function testToArrayExposesTheWireShape(): void
	{
		$response = new Response(201, ['X' => '1'], 'y');

		self::assertSame(
			['status' => 201, 'headers' => ['X' => '1'], 'body' => 'y'],
			$response->toArray(),
		);
	}

	public function testRefusesToEncodeInvalidUtf8(): void
	{
		$this->expectException(JsonException::class);

		Response::json(['bad' => "\xB1\x31"]);
	}
}
