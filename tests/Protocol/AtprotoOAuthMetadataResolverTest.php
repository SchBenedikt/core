<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthException;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoOAuthMetadataResolver;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoOAuthMetadataResolverTest extends TestCase
{
	private const PDS = 'https://pds.example';

	private const ISSUER = 'https://auth.example';

	public function testItDiscoversAndValidatesTheAuthorizationServer(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'resource' => self::PDS,
			'authorization_servers' => [self::ISSUER],
		], JSON_THROW_ON_ERROR)));
		$http->queueResponse(new HttpResponse(200, [], json_encode($this->metadata(), JSON_THROW_ON_ERROR)));

		$server = (new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS . '/');

		self::assertSame(self::ISSUER, $server->issuer);
		self::assertSame(self::ISSUER . '/oauth/authorize', $server->authorizationEndpoint);
		self::assertSame(self::ISSUER . '/oauth/token', $server->tokenEndpoint);
		self::assertSame(self::ISSUER . '/oauth/par', $server->parEndpoint);
		self::assertSame(self::ISSUER . '/oauth/revoke', $server->revocationEndpoint);

		self::assertCount(2, $http->requests);
		self::assertSame('GET', $http->requests[0]['method']);
		self::assertSame(self::PDS . '/.well-known/oauth-protected-resource', $http->requests[0]['url']);
		self::assertSame('GET', $http->requests[1]['method']);
		self::assertSame(self::ISSUER . '/.well-known/oauth-authorization-server', $http->requests[1]['url']);
		self::assertSame('application/json', $http->requests[0]['headers']['Accept']);
	}

	public function testItRejectsMoreThanOneAuthorizationServer(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"authorization_servers":["https://a.example","https://b.example"]}'));

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('exactly one');

		(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
	}

	public function testItRejectsAnIssuerThatDoesNotMatchTheDiscoveredOrigin(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"authorization_servers":["https://auth.example"]}'));
		$metadata = $this->metadata();
		$metadata['issuer'] = 'https://evil.example';
		$http->queueResponse(new HttpResponse(200, [], json_encode($metadata, JSON_THROW_ON_ERROR)));

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('issuer');

		(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
	}

	public function testItRequiresPushedAuthorizationAndS256(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"authorization_servers":["https://auth.example"]}'));
		$metadata = $this->metadata();
		$metadata['require_pushed_authorization_requests'] = false;
		$http->queueResponse(new HttpResponse(200, [], json_encode($metadata, JSON_THROW_ON_ERROR)));

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('require_pushed_authorization_requests');

		(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
	}

	public function testItRequiresTheAtprotoScope(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"authorization_servers":["https://auth.example"]}'));
		$metadata = $this->metadata();
		$metadata['scopes_supported'] = ['repo:app.bsky.feed.post?action=create'];
		$http->queueResponse(new HttpResponse(200, [], json_encode($metadata, JSON_THROW_ON_ERROR)));

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('atproto');

		(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
	}

	public function testATransportFailureIsRetryableAndDoesNotLeakTheUrl(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('connection refused'));

		try {
			(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
			self::fail('The resolver should have failed.');
		} catch (AtprotoOAuthException $exception) {
			self::assertSame('transport_error', $exception->errorCode);
			self::assertTrue($exception->retryable);
			self::assertStringNotContainsString('connection refused', $exception->getMessage());
		}
	}

	public function testAnInvalidProtectedResourceDocumentIsRejected(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '[]'));

		$this->expectException(AtprotoOAuthException::class);
		$this->expectExceptionMessage('not a JSON object');

		(new AtprotoOAuthMetadataResolver($http))->resolve(self::PDS);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function metadata(): array
	{
		return [
			'issuer' => self::ISSUER,
			'authorization_endpoint' => self::ISSUER . '/oauth/authorize',
			'token_endpoint' => self::ISSUER . '/oauth/token',
			'pushed_authorization_request_endpoint' => self::ISSUER . '/oauth/par',
			'revocation_endpoint' => self::ISSUER . '/oauth/revoke',
			'require_pushed_authorization_requests' => true,
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported' => true,
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none'],
			'dpop_signing_alg_values_supported' => ['ES256'],
			'scopes_supported' => ['atproto', 'transition:generic'],
		];
	}
}
