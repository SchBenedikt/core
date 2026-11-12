<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Http;

use AlohaSocial\Core\Platform\Http\CurlHttpClient;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Tests\Support\RemoteMediaServer;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
	private static RemoteMediaServer $server;

	public static function setUpBeforeClass(): void
	{
		self::$server = new RemoteMediaServer();
	}

	public static function tearDownAfterClass(): void
	{
		self::$server->stop();
	}

	public function testARequestReturnsTheBodyAndHeadersWithoutFollowingRedirects(): void
	{
		$client = new CurlHttpClient();

		$response = $client->request('GET', self::$server->baseUrl . '/image.png');

		self::assertSame(200, $response->status);
		self::assertSame('image/png', $response->header('content-type'));
		self::assertSame(self::$server->fixtureBytes, $response->body);

		$redirect = $client->request('GET', self::$server->baseUrl . '/redirect-image');
		self::assertSame(302, $redirect->status);
		self::assertSame('/image.png', $redirect->header('location'));
		self::assertSame('', $redirect->body);
	}

	public function testValidatedAddressesArePinnedForTheConnection(): void
	{
		$port = parse_url(self::$server->baseUrl, PHP_URL_PORT);
		self::assertIsInt($port);

		$response = (new CurlHttpClient())->requestBounded(
			'GET',
			'http://media.remote.example:' . $port . '/image.png',
			resolvedIps: ['127.0.0.1'],
		);

		self::assertSame(200, $response->status);
		self::assertSame(self::$server->fixtureBytes, $response->body);
	}

	public function testTheBoundedRequestStopsAnOversizedBody(): void
	{
		$client = new CurlHttpClient();

		$this->expectException(ResponseLimitExceededException::class);

		$client->requestBounded(
			'GET',
			self::$server->baseUrl . '/image.png',
			maxBytes: 10,
		);
	}
}
