<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Protocol\Atproto\Es256DpopProofProvider;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Support\Time;
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

		$published = (new XrpcAtprotoPublisher($http))->publishRecord(
			$this->account(),
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);

		self::assertSame(self::URI, $published->uri);
		self::assertSame('bafyrei', $published->cid);
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

	public function testAResponseForAnotherRepositoryRecordIsRejected(): void
	{
		foreach ([
			'at://did:plc:mallory/app.bsky.feed.post/234567abcdefg',
			'at://did:plc:alice/app.bsky.feed.like/234567abcdefg',
			'at://did:plc:alice/app.bsky.feed.post/another-key',
		] as $uri) {
			$http = new FakeHttpClient();
			$http->queueResponse(new HttpResponse(200, [], json_encode(['uri' => $uri, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR)));

			try {
				(new XrpcAtprotoPublisher($http))->publish(
					$this->account(),
					'234567abcdefg',
					AtprotoPostRecord::fromPost($this->post()),
				);
				self::fail('A PDS response for a different record must be rejected.');
			} catch (AtprotoPublishFailedException $exception) {
				self::assertSame(502, $exception->status);
			}

			self::assertCount(1, $http->requests);
		}
	}

	public function testACustomCollectionIsWrittenAndDeleted(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => 'at://did:plc:alice/app.bsky.feed.like/rkey1',
			'cid' => 'bafy-like',
		], JSON_THROW_ON_ERROR)));
		$publisher = new XrpcAtprotoPublisher($http);

		$publisher->publish($this->account(), 'rkey1', [
			'$type' => 'app.bsky.feed.like',
			'subject' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/abc', 'cid' => 'bafy-subject'],
		], 'app.bsky.feed.like');

		$body = json_decode($http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.like', $body['collection']);

		$http->queueResponse(new HttpResponse(200, [], '{}'));
		$publisher->delete($this->account(), 'rkey1', 'app.bsky.feed.like');

		$delete = json_decode($http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.like', $delete['collection']);
		self::assertSame('rkey1', $delete['rkey']);
	}

	public function testAResponseWithoutARecordCidIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode(['uri' => self::URI], JSON_THROW_ON_ERROR)));

		$this->expectException(AtprotoPublishFailedException::class);
		(new XrpcAtprotoPublisher($http))->publishRecord(
			$this->account(),
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);
	}

	public function testAnInvalidCollectionIsRejectedBeforeTheRequest(): void
	{
		$http = new FakeHttpClient();
		$publisher = new XrpcAtprotoPublisher($http);

		$this->expectException(\InvalidArgumentException::class);

		try {
			$publisher->delete($this->account(), 'rkey1', 'not a nsid');
		} finally {
			self::assertCount(0, $http->requests);
		}
	}

	public function testADpopAccountSendsADpopResourceRequest(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode(['uri' => self::URI, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR)));

		$uri = (new XrpcAtprotoPublisher($http, $this->dpop()))->publish(
			$this->account(),
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);

		self::assertSame(self::URI, $uri);
		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('DPoP secret-token', $request['headers']['Authorization']);

		$proof = $this->proofPayload($request['headers']['DPoP']);
		self::assertSame('POST', $proof['htm']);
		self::assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $proof['htu']);
		self::assertSame($this->accessTokenHash('secret-token'), $proof['ath']);
		self::assertArrayNotHasKey('nonce', $proof);
	}

	public function testAccountBoundDpopKeyOverridesTheHostDefault(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode(['uri' => self::URI, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR)));
		$accountKey = $this->dpop();
		$hostKey = $this->dpop();
		$account = new AtprotoAccount(
			'did:plc:alice',
			'alice.test',
			'https://pds.example',
			'secret-token',
			$accountKey,
		);

		(new XrpcAtprotoPublisher($http, $hostKey))->publish(
			$account,
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);

		$proof = $http->requests[0]['headers']['DPoP'];
		self::assertSame($this->proofHeader($accountKey->proof('POST', 'https://pds.example/proof'))['jwk'], $this->proofHeader($proof)['jwk']);
		self::assertNotSame($this->proofHeader($hostKey->proof('POST', 'https://pds.example/proof'))['jwk'], $this->proofHeader($proof)['jwk']);
	}

	public function testADpopNonceChallengeIsRetriedOnce(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, ['dpop-nonce' => 'nonce-1'], '{"error":"use_dpop_nonce"}'));
		$http->queueResponse(new HttpResponse(200, [], json_encode(['uri' => self::URI, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR)));

		$uri = (new XrpcAtprotoPublisher($http, $this->dpop()))->publish(
			$this->account(),
			'234567abcdefg',
			AtprotoPostRecord::fromPost($this->post()),
		);

		self::assertSame(self::URI, $uri);
		self::assertCount(2, $http->requests);
		self::assertArrayNotHasKey('nonce', $this->proofPayload($http->requests[0]['headers']['DPoP']));
		self::assertSame('nonce-1', $this->proofPayload($http->requests[1]['headers']['DPoP'])['nonce']);
	}

	public function testASecondNonceChallengeIsNotRetried(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, ['dpop-nonce' => 'nonce-1'], '{"error":"use_dpop_nonce"}'));
		$http->queueResponse(new HttpResponse(400, ['dpop-nonce' => 'nonce-2'], '{"error":"use_dpop_nonce"}'));
		$publisher = new XrpcAtprotoPublisher($http, $this->dpop());

		try {
			$publisher->publish($this->account(), '234567abcdefg', AtprotoPostRecord::fromPost($this->post()));
			self::fail('The publisher should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(400, $exception->status);
			self::assertStringNotContainsString('use_dpop_nonce', $exception->getMessage());
		}

		self::assertCount(2, $http->requests);
	}

	public function testADpopChallengeWithoutNonceIsNotRetried(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, [], '{"error":"use_dpop_nonce"}'));
		$publisher = new XrpcAtprotoPublisher($http, $this->dpop());

		try {
			$publisher->publish($this->account(), '234567abcdefg', AtprotoPostRecord::fromPost($this->post()));
			self::fail('The publisher should have failed.');
		} catch (AtprotoPublishFailedException $exception) {
			self::assertSame(401, $exception->status);
		}

		self::assertCount(1, $http->requests);
	}

	public function testADpopDeleteSendsTheProofToo(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(404, [], '{"error":"RecordNotFound"}'));

		(new XrpcAtprotoPublisher($http, $this->dpop()))->delete($this->account(), '234567abcdefg');

		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('DPoP secret-token', $request['headers']['Authorization']);
		self::assertSame(
			'https://pds.example/xrpc/com.atproto.repo.deleteRecord',
			$this->proofPayload($request['headers']['DPoP'])['htu'],
		);
	}

	private function dpop(): Es256DpopProofProvider
	{
		$key = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);
		self::assertNotFalse($key);
		self::assertTrue(openssl_pkey_export($key, $privateKey));

		return new Es256DpopProofProvider(
			$privateKey,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function proofPayload(string $proof): array
	{
		$parts = explode('.', $proof);
		self::assertCount(3, $parts);

		return json_decode(
			base64_decode(
				strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4),
				true,
			),
			true,
			64,
			JSON_THROW_ON_ERROR,
		);
	}

	/** @return array<string, mixed> */
	private function proofHeader(string $proof): array
	{
		$parts = explode('.', $proof);

		return json_decode(
			base64_decode(
				strtr($parts[0], '-_', '+/') . str_repeat('=', (4 - strlen($parts[0]) % 4) % 4),
				true,
			),
			true,
			64,
			JSON_THROW_ON_ERROR,
		);
	}

	private function accessTokenHash(string $accessToken): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
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
