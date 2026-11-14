<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoRecordResolverTest extends TestCase
{
	private const URI = 'at://did:plc:alice/app.bsky.feed.post/parent1';

	public function testItReadsARecordWithItsCid(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => self::URI,
			'cid' => 'bafy-parent',
			'value' => ['text' => 'root', 'reply' => ['root' => ['uri' => self::URI, 'cid' => 'bafy-parent']]],
		], JSON_THROW_ON_ERROR)));

		$record = (new XrpcAtprotoRecordResolver($http))->getRecord('https://pds.example', self::URI);

		self::assertNotNull($record);
		self::assertSame(self::URI, $record->uri);
		self::assertSame('bafy-parent', $record->cid);
		self::assertSame('root', $record->record['text']);
		self::assertSame(
			'https://pds.example/xrpc/com.atproto.repo.getRecord?uri='
				. rawurlencode(self::URI),
			$http->requests[0]['url'],
		);
		self::assertSame('GET', $http->requests[0]['method']);
	}

	public function testAMissingRecordIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, [], '{"error":"RecordNotFound"}'));

		self::assertNull((new XrpcAtprotoRecordResolver($http))->getRecord('https://pds.example', self::URI));
	}

	public function testARecordWithoutACidIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"uri":"' . self::URI . '","value":{"text":"x"}}'));

		self::assertNull((new XrpcAtprotoRecordResolver($http))->getRecord('https://pds.example', self::URI));
	}

	public function testATransportFailureIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('offline'));

		self::assertNull((new XrpcAtprotoRecordResolver($http))->getRecord('https://pds.example', self::URI));
	}

	public function testAMalformedBodyIsUnresolved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], 'not json'));

		self::assertNull((new XrpcAtprotoRecordResolver($http))->getRecord('https://pds.example', self::URI));
	}
}
