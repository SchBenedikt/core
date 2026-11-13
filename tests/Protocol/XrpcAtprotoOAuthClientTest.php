<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthAuthorizationRequest;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthException;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthServer;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoOAuthClient;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\RecordingDpopProofProvider;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoOAuthClientTest extends TestCase
{
	private const CLIENT_ID = 'https://app.example/client.json';

	private const REDIRECT_URI = 'https://app.example/oauth/callback';

	private const DID = 'did:plc:alice';

	private const PDS = 'https://pds.example';

	public function testItStartsAFlowWithParPkceAndADpopBinding(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(201, ['dpop-nonce' => 'nonce-1'], '{"request_uri":"urn:ietf:params:oauth:request_uri:one"}'));
		$dpop = new RecordingDpopProofProvider('key-thumbprint');

		$request = $this->client($http, $dpop)->begin($this->server(), self::DID, self::PDS, 'alice.test');

		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $request->state);
		self::assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{43}$/', $request->codeVerifier);
		self::assertSame(self::DID, $request->did);
		self::assertSame(self::PDS, $request->pdsUrl);
		self::assertSame('nonce-1', $request->dpopNonce);
		self::assertSame(1, $dpop->thumbprintCalls);
		self::assertCount(1, $http->requests);

		$par = $http->requests[0];
		self::assertSame('POST', $par['method']);
		self::assertSame('https://auth.example/oauth/par', $par['url']);
		self::assertSame('application/x-www-form-urlencoded', $par['headers']['Content-Type']);
		self::assertSame('proof-1', $par['headers']['DPoP']);

		parse_str($par['body'], $form);
		self::assertSame(self::CLIENT_ID, $form['client_id']);
		self::assertSame('code', $form['response_type']);
		self::assertSame(self::REDIRECT_URI, $form['redirect_uri']);
		self::assertSame('atproto', $form['scope']);
		self::assertSame($request->state, $form['state']);
		self::assertSame('S256', $form['code_challenge_method']);
		self::assertSame('key-thumbprint', $form['dpop_jkt']);
		self::assertSame('alice.test', $form['login_hint']);
		self::assertSame(
			rtrim(strtr(base64_encode(hash('sha256', $request->codeVerifier, true)), '+/', '-_'), '='),
			$form['code_challenge'],
		);

		$authorization = parse_url($request->authorizationUrl);
		self::assertSame('https', $authorization['scheme']);
		self::assertSame('auth.example', $authorization['host']);
		self::assertSame('/oauth/authorize', $authorization['path']);
		parse_str($authorization['query'] ?? '', $query);
		self::assertSame(self::CLIENT_ID, $query['client_id']);
		self::assertSame('urn:ietf:params:oauth:request_uri:one', $query['request_uri']);
	}

	public function testItRetriesParOnceWithTheServerNonce(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, ['dpop-nonce' => 'nonce-1'], '{"error":"use_dpop_nonce"}'));
		$http->queueResponse(new HttpResponse(201, ['dpop-nonce' => 'nonce-2'], '{"request_uri":"urn:one"}'));
		$dpop = new RecordingDpopProofProvider();

		$request = $this->client($http, $dpop)->begin($this->server(), self::DID, self::PDS);

		self::assertSame('nonce-2', $request->dpopNonce);
		self::assertSame([null, 'nonce-1'], array_column($dpop->calls, 'nonce'));
		self::assertSame('proof-2', $http->requests[1]['headers']['DPoP']);
	}

	public function testAnOAuthRefusalDoesNotLeakTheResponseBody(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(400, [], '{"error":"invalid_request","error_description":"secret-verifier"}'));

		try {
			$this->client($http, new RecordingDpopProofProvider())->begin($this->server(), self::DID, self::PDS);
			self::fail('The client should have failed.');
		} catch (AtprotoOAuthException $exception) {
			self::assertSame(400, $exception->status);
			self::assertSame('invalid_request', $exception->errorCode);
			self::assertFalse($exception->retryable);
			self::assertStringNotContainsString('secret-verifier', $exception->getMessage());
		}
	}

	public function testItExchangesTheCodeAndChecksIssuerAndDid(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce-2'], json_encode([
			'access_token' => 'access-1',
			'refresh_token' => 'refresh-1',
			'token_type' => 'DPoP',
			'scope' => 'atproto transition:generic',
			'sub' => self::DID,
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$dpop = new RecordingDpopProofProvider();

		$session = $this->client($http, $dpop)->complete($this->request(), 'code-1', 'https://auth.example');

		self::assertSame('access-1', $session->accessToken);
		self::assertSame('refresh-1', $session->refreshToken);
		self::assertSame(self::DID, $session->did);
		self::assertSame('https://auth.example/oauth/token', $session->tokenEndpoint);
		self::assertSame('2026-11-11T10:05:00Z', Time::format($session->expiresAt));
		self::assertSame('nonce-1', $dpop->calls[0]['nonce']);

		parse_str($http->requests[0]['body'], $form);
		self::assertSame('authorization_code', $form['grant_type']);
		self::assertSame('code-1', $form['code']);
		self::assertSame(self::CLIENT_ID, $form['client_id']);
		self::assertSame(self::REDIRECT_URI, $form['redirect_uri']);
		self::assertSame(str_repeat('a', 43), $form['code_verifier']);
	}

	public function testItRejectsAnAuthorizationResponseFromAnotherIssuerBeforeAnyRequest(): void
	{
		$http = new FakeHttpClient();

		try {
			$this->client($http, new RecordingDpopProofProvider())->complete(
				$this->request(),
				'code-1',
				'https://evil.example',
			);
			self::fail('The client should have failed.');
		} catch (AtprotoOAuthException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
			self::assertStringContainsString('issuer', $exception->getMessage());
			self::assertCount(0, $http->requests);
		}
	}

	public function testItRejectsATokenForAnotherDid(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce-2'], json_encode([
			'access_token' => 'access-1',
			'refresh_token' => 'refresh-1',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => 'did:plc:mallory',
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));

		try {
			$this->client($http, new RecordingDpopProofProvider())->complete(
				$this->request(),
				'code-1',
				'https://auth.example',
			);
			self::fail('The client should have failed.');
		} catch (AtprotoOAuthException $exception) {
			self::assertSame('invalid_response', $exception->errorCode);
			self::assertStringContainsString('sub', $exception->getMessage());
		}
	}

	public function testItRetriesTheTokenExchangeWithTheNewNonce(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(401, ['dpop-nonce' => 'nonce-2'], '{"error":"use_dpop_nonce"}'));
		$http->queueResponse(new HttpResponse(200, ['dpop-nonce' => 'nonce-3'], json_encode([
			'access_token' => 'access-1',
			'refresh_token' => 'refresh-1',
			'token_type' => 'DPoP',
			'scope' => 'atproto',
			'sub' => self::DID,
			'expires_in' => 300,
		], JSON_THROW_ON_ERROR)));
		$dpop = new RecordingDpopProofProvider();

		$session = $this->client($http, $dpop)->complete($this->request(), 'code-1', 'https://auth.example');

		self::assertSame('access-1', $session->accessToken);
		self::assertSame(['nonce-1', 'nonce-2'], array_column($dpop->calls, 'nonce'));
	}

	public function testItRevokesTheRefreshToken(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(204, ['dpop-nonce' => 'nonce-1'], ''));

		$this->client($http, new RecordingDpopProofProvider())->revoke($this->server(), $this->session());

		parse_str($http->requests[0]['body'], $form);
		self::assertSame('refresh-1', $form['token']);
		self::assertSame('refresh_token', $form['token_type_hint']);
		self::assertSame(self::CLIENT_ID, $form['client_id']);
		self::assertSame('https://auth.example/oauth/revoke', $http->requests[0]['url']);
	}

	public function testItRefusesRevocationWhenTheServerHasNoRevocationEndpoint(): void
	{
		$server = new AtprotoOAuthServer(
			'https://auth.example',
			'https://auth.example/oauth/authorize',
			'https://auth.example/oauth/token',
			'https://auth.example/oauth/par',
		);

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('revocation');

		$this->client(new FakeHttpClient(), new RecordingDpopProofProvider())->revoke($server, $this->session());
	}

	public function testATransportFailureDoesNotLeakTheTokenEndpoint(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('dns failure'));

		try {
			$this->client($http, new RecordingDpopProofProvider())->complete(
				$this->request(),
				'code-1',
				'https://auth.example',
			);
			self::fail('The client should have failed.');
		} catch (AtprotoOAuthException $exception) {
			self::assertSame('transport_error', $exception->errorCode);
			self::assertTrue($exception->retryable);
			self::assertStringNotContainsString('dns failure', $exception->getMessage());
		}
	}

	private function client(FakeHttpClient $http, RecordingDpopProofProvider $dpop): XrpcAtprotoOAuthClient
	{
		return new XrpcAtprotoOAuthClient(
			$http,
			$dpop,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			self::CLIENT_ID,
			self::REDIRECT_URI,
		);
	}

	private function server(): AtprotoOAuthServer
	{
		return new AtprotoOAuthServer(
			'https://auth.example',
			'https://auth.example/oauth/authorize',
			'https://auth.example/oauth/token',
			'https://auth.example/oauth/par',
			'https://auth.example/oauth/revoke',
		);
	}

	private function request(): AtprotoOAuthAuthorizationRequest
	{
		return new AtprotoOAuthAuthorizationRequest(
			str_repeat('s', 32),
			str_repeat('a', 43),
			self::DID,
			self::PDS,
			$this->server(),
			'https://auth.example/oauth/authorize?client_id=' . rawurlencode(self::CLIENT_ID),
			'nonce-1',
		);
	}

	private function session(): \AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession
	{
		return new \AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession(
			self::DID,
			'https://auth.example/oauth/token',
			self::CLIENT_ID,
			'access-1',
			'refresh-1',
			'atproto',
			Time::parse('2026-11-11T10:05:00Z'),
		);
	}
}
