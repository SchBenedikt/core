<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoPublisherTest extends TestCase
{
	private const URI = 'at://did:plc:alice/app.bsky.feed.post/234567abcdefg';

	public function testItSendsAnAuthenticatedPutRecord(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => self::URI,
			'cid' => 'bafyrei',
		], JSON_THROW_ON_ERROR)));

		$uri = (new XrpcAtprotoPublisher($http))->publish(
			$this->account(),
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);

		self::assertSame(self::URI, $uri);
		self::assertCount(1, $http->requests);
		$request = $http->requests[0];

		self::assertSame('POST', $request['method']);
		self::assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $request['url']);
		self::assertSame('Bearer secret-token', $request['headers']['Authorization']);
		self::assertSame('application/json', $request['headers']['Content-Type']);
		self::assertSame(20, $request['timeout']);

		$body = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('did:plc:alice', $body['repo']);
		self::assertSame('app.bsky.feed.post', $body['collection']);
		self::assertSame('234567abcdefg', $body['rkey']);
		self::assertTrue($body['validate']);
		self::assertSame('hello', $body['record']['text']);
	}

	public function testItSendsAnAuthenticatedDeleteRecordAndAccepts404AsIdempotent(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(404, [], '{"error":"RecordNotFound"}'));

		(new XrpcAtprotoPublisher($http))->delete($this->account(), '234567abcdefg');

		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame('https://pds.example/xrpc/com.atproto.repo.deleteRecord', $request['url']);
		self::assertSame('Bearer secret-token', $request['headers']['Authorization']);

		$body = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('did:plc:alice', $body['repo']);
		self::assertSame('app.bsky.feed.post', $body['collection']);
		self::assertSame('234567abcdefg', $body['rkey']);
		self::assertArrayNotHasKey('record', $body);
	}

	public function testADeleteRefusalCarriesTheStatusButNoBody(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, [], '{"error":"InvalidToken","message":"secret"}'));

		try {
			(new XrpcAtprotoPublisher($http))->delete($this->account(), '234567abcdefg');
			self::fail('The deleter should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(401, $exception->status);
			self::assertStringNotContainsString('secret', $exception->getMessage());
			self::assertStringNotContainsString('InvalidToken', $exception->getMessage());
		}
	}

	public function testANonSuccessfulAnswerCarriesTheStatusButNoBody(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, [], '{"error":"InvalidToken","message":"secret"}'));
		$publisher = new XrpcAtprotoPublisher($http);

		try {
			$publisher->publish($this->account(), '234567abcdefg', AtprotoPostRecord::fromPost($this->post()));
			self::fail('The publisher should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(401, $exception->status);
			self::assertStringNotContainsString('secret', $exception->getMessage());
			self::assertStringNotContainsString('InvalidToken', $exception->getMessage());
		}
	}

	public function testTransportFailureIsRetryableAndStatusless(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('offline'));
		$publisher = new XrpcAtprotoPublisher($http);

		try {
			$publisher->publish($this->account(), '234567abcdefg', AtprotoPostRecord::fromPost($this->post()));
			self::fail('The publisher should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(0, $exception->status);
		}
	}

	public function testAMalformedSuccessAnswerIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"uri":42}'));
		$publisher = new XrpcAtprotoPublisher($http);

		try {
			$publisher->publish($this->account(), '234567abcdefg', AtprotoPostRecord::fromPost($this->post()));
			self::fail('The publisher should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(502, $exception->status);
		}
	}

	private function account(): AtprotoAccount
	{
		return new AtprotoAccount(
			'did:plc:alice',
			'alice.test',
			'https://pds.example',
			'secret-token',
		);
	}

	private function post(): \AlohaSocial\Core\Domain\Post\Post
	{
		return new \AlohaSocial\Core\Domain\Post\Post(
			'post-1',
			'alice',
			'hello',
			\AlohaSocial\Core\Domain\Post\Visibility::Public,
			\AlohaSocial\Core\Support\Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
