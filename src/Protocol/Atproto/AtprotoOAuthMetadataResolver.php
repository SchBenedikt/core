<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthException;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthServer;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function rtrim;
use function strlen;
use function strtolower;
use function trim;

/**
 * Discovers the authorization server of a PDS and validates its metadata.
 *
 * AT Protocol ties every PDS to exactly one authorization server. The
 * protected-resource document is fetched from the PDS and the authorization
 * server document from its issuer origin; neither URL comes from user input.
 */
final class AtprotoOAuthMetadataResolver
{
	public const PROTECTED_RESOURCE_PATH = '/.well-known/oauth-protected-resource';

	public const AUTHORIZATION_SERVER_PATH = '/.well-known/oauth-authorization-server';

	private const MAX_DOCUMENT_BYTES = 131_072;

	public function __construct(
		private readonly HttpClient $http,
		private readonly int $timeoutSeconds = 10,
	) {
		if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
			throw new InvalidArgumentException('The OAuth metadata timeout must be between 1 and 60 seconds.');
		}
	}

	/**
	 * @throws AtprotoOAuthException
	 */
	public function resolve(string $pdsUrl): AtprotoOAuthServer
	{
		$pdsUrl = rtrim($pdsUrl, '/');
		if ($pdsUrl === '' || $this->origin($pdsUrl) === null) {
			throw AtprotoOAuthException::invalidResponse('the PDS URL is not an absolute http(s) origin.');
		}

		$resource = $this->fetchDocument($pdsUrl . self::PROTECTED_RESOURCE_PATH, 'protected-resource document');
		$servers = $resource['authorization_servers'] ?? null;
		if (!is_array($servers) || !array_is_list($servers) || count($servers) !== 1 || !is_string($servers[0])) {
			throw AtprotoOAuthException::invalidResponse(
				'the protected-resource document must name exactly one authorization server.',
			);
		}

		$issuer = rtrim($servers[0], '/');
		if ($this->origin($issuer) === null) {
			throw AtprotoOAuthException::invalidResponse(
				'the protected-resource document contains an invalid authorization server.',
			);
		}

		$document = $this->fetchDocument($issuer . self::AUTHORIZATION_SERVER_PATH, 'authorization-server document');
		$documentIssuer = $document['issuer'] ?? null;
		if (!is_string($documentIssuer) || rtrim($documentIssuer, '/') !== $issuer) {
			throw AtprotoOAuthException::invalidResponse('the authorization-server issuer does not match the discovered issuer.');
		}

		foreach ([
			'authorization_endpoint',
			'token_endpoint',
			'pushed_authorization_request_endpoint',
		] as $field) {
			if (!isset($document[$field]) || !is_string($document[$field]) || trim($document[$field]) === '') {
				throw AtprotoOAuthException::invalidResponse('the authorization-server document is missing ' . $field . '.');
			}
		}

		$revocationEndpoint = $document['revocation_endpoint'] ?? null;
		if ($revocationEndpoint !== null && (!is_string($revocationEndpoint) || trim($revocationEndpoint) === '')) {
			throw AtprotoOAuthException::invalidResponse(
				'the authorization-server revocation endpoint is invalid.',
			);
		}

		$this->assertFlag($document, 'require_pushed_authorization_requests');
		$this->assertFlag($document, 'authorization_response_iss_parameter_supported');
		$this->assertFlag($document, 'client_id_metadata_document_supported');
		$this->assertListContains($document, 'response_types_supported', 'code');
		$this->assertListContains($document, 'grant_types_supported', 'authorization_code');
		$this->assertListContains($document, 'grant_types_supported', 'refresh_token');
		$this->assertListContains($document, 'code_challenge_methods_supported', 'S256');
		$this->assertListContains($document, 'token_endpoint_auth_methods_supported', 'none');
		$this->assertListContains($document, 'dpop_signing_alg_values_supported', 'ES256');
		$this->assertListContains($document, 'scopes_supported', 'atproto');

		return new AtprotoOAuthServer(
			$issuer,
			$document['authorization_endpoint'],
			$document['token_endpoint'],
			$document['pushed_authorization_request_endpoint'],
			$revocationEndpoint,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchDocument(string $url, string $label): array
	{
		try {
			$response = $this->http->request(
				'GET',
				$url,
				['Accept' => 'application/json'],
				'',
				$this->timeoutSeconds,
			);
		} catch (HttpClientException) {
			throw AtprotoOAuthException::transportFailure();
		}

		if (!$response->isSuccessful()) {
			throw AtprotoOAuthException::refused(
				$response->status,
				$this->errorCode($response->body),
				$response->status === 429 || $response->status >= 500,
			);
		}

		if (strlen($response->body) > self::MAX_DOCUMENT_BYTES) {
			throw AtprotoOAuthException::invalidResponse('the ' . $label . ' is too large.');
		}

		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw AtprotoOAuthException::invalidResponse('the ' . $label . ' is not valid JSON.');
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw AtprotoOAuthException::invalidResponse('the ' . $label . ' is not a JSON object.');
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function assertFlag(array $document, string $field): void
	{
		if (($document[$field] ?? null) !== true) {
			throw AtprotoOAuthException::invalidResponse(
				'the authorization-server document must set ' . $field . ' to true.',
			);
		}
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function assertListContains(array $document, string $field, string $required): void
	{
		$values = $document[$field] ?? null;
		if (!is_array($values) || !array_is_list($values) || !in_array($required, $values, true)) {
			throw AtprotoOAuthException::invalidResponse(
				'the authorization-server document does not support ' . $required . ' in ' . $field . '.',
			);
		}
	}

	private function origin(string $url): ?string
	{
		$parts = parse_url($url);
		if ($parts === false
			|| !isset($parts['scheme'], $parts['host'])
			|| (string) $parts['host'] === ''
			|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['path'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		) {
			return null;
		}

		$origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
		if (isset($parts['port'])) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	private function errorCode(string $body): string
	{
		try {
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return 'authorization_server_error';
		}

		$error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

		return is_string($error) && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $error) === 1
			? $error
			: 'authorization_server_error';
	}
}
