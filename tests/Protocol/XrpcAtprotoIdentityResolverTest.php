<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoIdentityResolver;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoIdentityResolverTest extends TestCase
{
	public function testItResolvesAHandleOnThePds(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"did":"did:plc:alice"}'));

		$did = (new XrpcAtprotoIdentityResolver($http))->resolveHandle('https://pds.example/', 'alice.test');

		self::assertSame('did:plc:alice', $did);
		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('GET', $request['method']);
		self::assertSame(
			'https://pds.example/xrpc/com.atproto.identity.resolveHandle?handle=alice.test',
			$request['url'],
		);
		self::assertSame('application/json', $request['headers']['Accept']);
		self::assertSame(10, $request['timeout']);
	}

	public function testAnUnknownHandleIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, [], '{"error":"InvalidRequest"}'));

		self::assertNull((new XrpcAtprotoIdentityResolver($http))->resolveHandle('https://pds.example', 'nope.test'));
	}

	public function testATransportFailureIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('offline'));

		self::assertNull((new XrpcAtprotoIdentityResolver($http))->resolveHandle('https://pds.example', 'alice.test'));
	}

	public function testAnInvalidDidIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"did":"alice.test"}'));

		self::assertNull((new XrpcAtprotoIdentityResolver($http))->resolveHandle('https://pds.example', 'alice.test'));
	}

	public function testAnInvalidHandleIsNotSent(): void
	{
		$http = new FakeHttpClient();

		self::assertNull((new XrpcAtprotoIdentityResolver($http))->resolveHandle('https://pds.example', 'not a handle'));
		self::assertCount(0, $http->requests);
	}
}
