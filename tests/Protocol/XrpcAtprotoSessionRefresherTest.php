<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefreshException;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoSessionRefresher;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\RecordingDpopProofProvider;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoSessionRefresherTest extends TestCase
{
	private const ENDPOINT = 'https://auth.example/oauth/token';

	public function testItRefreshesAndRotatesBothTokens(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce-1'], json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'DPoP',
			'scope' => 'atproto repo:app.bsky.feed.post?action=create',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$dpop = new RecordingDpopProofProvider();
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			$dpop,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$session = $refresher->refresh($this->session());

		self::assertSame('new-access', $session->accessToken);
		self::assertSame('new-refresh', $session->refreshToken);
		self::assertSame('2026-11-11T10:05:00Z', Time::format($session->expiresAt));
		self::assertSame('did:plc:alice', $session->did);
		self::assertSame(self::ENDPOINT, $session->tokenEndpoint);

		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame(self::ENDPOINT, $request['url']);
		self::assertSame('application/x-www-form-urlencoded', $request['headers']['Content-Type']);
		self::assertSame('application/json', $request['headers']['Accept']);
		self::assertSame('proof-1', $request['headers']['DPoP']);
		self::assertSame(
			'grant_type=refresh_token&refresh_token=refresh%2Btoken&client_id=https%3A%2F%2Fapp.example%2Fclient.json',
			$request['body'],
		);
		self::assertSame([['method' => 'POST', 'url' => self::ENDPOINT, 'nonce' => null]], $dpop->calls);
	}

	public function testItRetriesOnceWithTheServerDpopNonce(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, ['dpop-nonce' => 'nonce-1'], '{"error":"use_dpop_nonce"}'));
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce-2'], json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$dpop = new RecordingDpopProofProvider();
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			$dpop,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$session = $refresher->refresh($this->session());

		self::assertSame('new-access', $session->accessToken);
		self::assertCount(2, $dpop->calls);
		self::assertNull($dpop->calls[0]['nonce']);
		self::assertSame('nonce-1', $dpop->calls[1]['nonce']);
		self::assertSame('proof-2', $http->requests[1]['headers']['DPoP']);
	}

	public function testAnInvalidGrantIsTerminalAndDoesNotLeakTheBody(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, [], '{"error":"invalid_grant","error_description":"secret-refresh-token"}'));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame(400, $exception->status);
			self::assertSame('invalid_grant', $exception->errorCode);
			self::assertFalse($exception->retryable);
			self::assertStringNotContainsString('secret-refresh-token', $exception->getMessage());
		}
	}

	public function testAServerFailureIsRetryable(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(503, [], '{"error":"temporarily_unavailable"}'));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame(503, $exception->status);
			self::assertTrue($exception->retryable);
		}
	}

	public function testASuccessWithoutADpopNonceIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], $this->successBody()));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('dpop_nonce_missing', $exception->errorCode);
			self::assertFalse($exception->retryable);
		}
	}

	public function testANonceChallengeWithoutANonceIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, [], '{"error":"use_dpop_nonce"}'));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('dpop_nonce_missing', $exception->errorCode);
		}
	}

	public function testASuccessWithoutARotatedRefreshTokenIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce'], json_encode([
			'access_token' => 'new-access',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
		}
	}

	public function testAWrongTokenTypeIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce'], json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'Bearer',
			'scope' => 'atproto',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
		}
	}

	public function testAMalformedSuccessBodyIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce'], 'not-json'));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
			self::assertTrue($exception->retryable);
		}
	}

	public function testASuccessWithoutTheAtprotoScopeIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce'], json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'DPoP',
			'scope' => 'repo:app.bsky.feed.post?action=create',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
			self::assertSame(502, $exception->status);
		}
	}

	public function testASuccessForAnotherDidIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce'], json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => 'did:plc:mallory',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
		}
	}

	public function testATransportFailureIsRetryable(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('offline'));
		$refresher = new XrpcAtprotoSessionRefresher(
			$http,
			new RecordingDpopProofProvider(),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		try {
			$refresher->refresh($this->session());
			self::fail('The refresher should have failed.');
		} catch (AtprotoSessionRefreshException $exception) {
			self::assertSame(0, $exception->status);
			self::assertTrue($exception->retryable);
		}
	}

	private function successBody(): string
	{
		return json_encode([
			'access_token' => 'new-access',
			'refresh_token' => 'new-refresh',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => 'did:plc:alice',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR);
	}

	private function session(): AtprotoOAuthSession
	{
		return new AtprotoOAuthSession(
			'did:plc:alice',
			self::ENDPOINT,
			'https://app.example/client.json',
			'old-access',
			'refresh+token',
			'atproto',
			Time::parse('2026-11-11T10:01:00Z'),
		);
	}
}
