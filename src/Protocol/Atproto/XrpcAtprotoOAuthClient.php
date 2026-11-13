<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthAuthorizationRequest;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthException;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthServer;
use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function base64_encode;
use function hash;
use function http_build_query;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function preg_split;
use function random_bytes;
use function rtrim;
use function str_contains;
use function strcasecmp;
use function strlen;
use function strtolower;
use function strtr;
use function trim;

/**
 * The AT Protocol authorization-code client.
 *
 * AT Protocol requires a pushed authorization request (PAR), PKCE with S256
 * and a DPoP proof on every token request. This class owns the protocol
 * mechanics only: the host owns the browser redirect, the short-lived flow
 * store and the eventual credential store. It never writes tokens to a log.
 */
final class XrpcAtprotoOAuthClient
{
	public const TIMEOUT_SECONDS = 20;

	private const STATE_BYTES = 32;

	private const VERIFIER_BYTES = 32;

	private readonly string $clientId;

	private readonly string $redirectUri;

	private readonly string $scope;

	public function __construct(
		private readonly HttpClient $http,
		private readonly DpopProofProvider $dpop,
		private readonly Clock $clock,
		string $clientId,
		string $redirectUri,
		string $scope = 'atproto',
	) {
		if (!$this->isClientId($clientId)) {
			throw new InvalidArgumentException('The AT Protocol OAuth client id must be an absolute http(s) URL.');
		}

		if (!$this->isRedirectUri($redirectUri)) {
			throw new InvalidArgumentException('The AT Protocol OAuth redirect URI must be an absolute http(s) URL.');
		}

		if ($scope === '' || trim($scope) !== $scope || strlen($scope) > AtprotoOAuthSession::MAX_SCOPE_LENGTH) {
			throw new InvalidArgumentException('The AT Protocol OAuth scope is invalid.');
		}

		if (!$this->hasAtprotoScope($scope)) {
			throw new InvalidArgumentException('The AT Protocol OAuth scope must contain "atproto".');
		}

		$this->clientId = $clientId;
		$this->redirectUri = $redirectUri;
		$this->scope = $scope;
	}

	/**
	 * Starts an authorization flow with PAR and returns the browser URL.
	 *
	 * The returned request contains the state and PKCE verifier. The host must
	 * persist it by state until the callback and must not send the verifier to
	 * the browser.
	 *
	 * @throws AtprotoOAuthException
	 */
	public function begin(
		AtprotoOAuthServer $server,
		string $did,
		string $pdsUrl,
		?string $loginHint = null,
	): AtprotoOAuthAuthorizationRequest {
		if (preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1) {
			throw new InvalidArgumentException('An AT Protocol authorization flow needs a valid DID.');
		}

		if ($pdsUrl === '' || trim($pdsUrl) !== $pdsUrl || strlen($pdsUrl) > AtprotoOAuthSession::MAX_ENDPOINT_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol authorization flow needs a valid PDS URL.');
		}

		$pds = parse_url($pdsUrl);
		if ($pds === false
			|| !isset($pds['scheme'], $pds['host'])
			|| (string) $pds['host'] === ''
			|| !in_array(strtolower((string) $pds['scheme']), ['http', 'https'], true)
			|| isset($pds['user'])
			|| isset($pds['pass'])
			|| isset($pds['query'])
			|| isset($pds['fragment'])
		) {
			throw new InvalidArgumentException('An AT Protocol authorization flow needs an absolute PDS URL.');
		}

		if ($loginHint !== null && ($loginHint === '' || trim($loginHint) !== $loginHint || strlen($loginHint) > 512)) {
			throw new InvalidArgumentException('An AT Protocol login hint is invalid.');
		}

		if (!$this->dpop instanceof DpopKeyThumbprint) {
			throw AtprotoOAuthException::configuration('the DPoP provider cannot expose a key thumbprint.');
		}

		$thumbprint = $this->dpop->thumbprint();
		if ($thumbprint === '' || trim($thumbprint) !== $thumbprint) {
			throw AtprotoOAuthException::configuration('the DPoP key thumbprint is empty.');
		}

		$state = $this->randomToken(self::STATE_BYTES);
		$verifier = $this->randomToken(self::VERIFIER_BYTES);
		$challenge = $this->base64Url(hash('sha256', $verifier, true));

		$form = [
			'client_id' => $this->clientId,
			'response_type' => 'code',
			'redirect_uri' => $this->redirectUri,
			'scope' => $this->scope,
			'state' => $state,
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
			'dpop_jkt' => $thumbprint,
		];
		if ($loginHint !== null) {
			$form['login_hint'] = $loginHint;
		}

		$nonce = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$response = $this->postForm($server->parEndpoint, $form, $nonce);
			$nextNonce = $this->nonce($response);

			if ($response->isSuccessful()) {
				if ($nextNonce === null) {
					throw AtprotoOAuthException::missingNonce();
				}

				$requestUri = $this->requestUri($response);
				$separator = str_contains($server->authorizationEndpoint, '?') ? '&' : '?';
				$authorizationUrl = $server->authorizationEndpoint . $separator . http_build_query([
					'client_id' => $this->clientId,
					'request_uri' => $requestUri,
				], '', '&', PHP_QUERY_RFC3986);

				return new AtprotoOAuthAuthorizationRequest(
					$state,
					$verifier,
					$did,
					$pdsUrl,
					$server,
					$authorizationUrl,
					$nextNonce,
				);
			}

			$errorCode = $this->errorCode($response);
			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce !== null && $attempt === 0) {
				$nonce = $nextNonce;
				continue;
			}

			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce === null) {
				throw AtprotoOAuthException::missingNonce();
			}

			throw AtprotoOAuthException::refused(
				$response->status,
				$errorCode,
				$response->status === 429 || $response->status >= 500,
			);
		}

		throw AtprotoOAuthException::refused(401, 'use_dpop_nonce', false);
	}

	/**
	 * Exchanges the authorization code and checks the response issuer and DID.
	 *
	 * The authorization response's `iss` is mandatory: without it a client
	 * cannot distinguish a response meant for another authorization server.
	 *
	 * @throws AtprotoOAuthException
	 */
	public function complete(
		AtprotoOAuthAuthorizationRequest $request,
		string $code,
		string $issuer,
	): AtprotoOAuthSession {
		if ($code === '' || trim($code) !== $code || strlen($code) > 8192) {
			throw AtprotoOAuthException::invalidResponse('the authorization code is missing or invalid.');
		}

		if (rtrim($issuer, '/') !== $request->server->issuer) {
			throw AtprotoOAuthException::invalidResponse('the authorization response issuer does not match.');
		}

		$form = [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->redirectUri,
			'client_id' => $this->clientId,
			'code_verifier' => $request->codeVerifier,
		];

		$nonce = $request->dpopNonce;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$response = $this->postForm($request->server->tokenEndpoint, $form, $nonce);
			$nextNonce = $this->nonce($response);

			if ($response->isSuccessful()) {
				if ($nextNonce === null) {
					throw AtprotoOAuthException::missingNonce();
				}

				return $this->parseSession($request, $response);
			}

			$errorCode = $this->errorCode($response);
			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce !== null && $attempt === 0) {
				$nonce = $nextNonce;
				continue;
			}

			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce === null) {
				throw AtprotoOAuthException::missingNonce();
			}

			throw AtprotoOAuthException::refused(
				$response->status,
				$errorCode,
				$response->status === 429 || $response->status >= 500,
			);
		}

		throw AtprotoOAuthException::refused(401, 'use_dpop_nonce', false);
	}

	/**
	 * Revokes the refresh token; a missing or already invalid token is a no-op.
	 *
	 * The access token is short-lived and is not revoked separately. The
	 * authorization server's revocation endpoint is a separate metadata field.
	 *
	 * @throws AtprotoOAuthException
	 */
	public function revoke(AtprotoOAuthServer $server, AtprotoOAuthSession $session): void
	{
		$endpoint = $server->revocationEndpoint;
		if ($endpoint === null) {
			throw AtprotoOAuthException::unsupported('no revocation endpoint is advertised.');
		}

		$form = [
			'token' => $session->refreshToken,
			'token_type_hint' => 'refresh_token',
			'client_id' => $this->clientId,
		];

		$nonce = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$response = $this->postForm($endpoint, $form, $nonce);
			$nextNonce = $this->nonce($response);

			if ($response->isSuccessful()) {
				return;
			}

			$errorCode = $this->errorCode($response);
			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce !== null && $attempt === 0) {
				$nonce = $nextNonce;
				continue;
			}

			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce === null) {
				throw AtprotoOAuthException::missingNonce();
			}

			throw AtprotoOAuthException::refused(
				$response->status,
				$errorCode,
				$response->status === 429 || $response->status >= 500,
			);
		}

		throw AtprotoOAuthException::refused(401, 'use_dpop_nonce', false);
	}

	/**
	 * @param array<string, string> $form
	 */
	private function postForm(string $url, array $form, ?string $nonce): HttpResponse
	{
		try {
			return $this->http->request(
				'POST',
				$url,
				[
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept' => 'application/json',
					'DPoP' => $this->dpop->proof('POST', $url, $nonce),
				],
				http_build_query($form, '', '&', PHP_QUERY_RFC3986),
				self::TIMEOUT_SECONDS,
			);
		} catch (HttpClientException) {
			throw AtprotoOAuthException::transportFailure();
		}
	}

	private function parseSession(
		AtprotoOAuthAuthorizationRequest $request,
		HttpResponse $response,
	): AtprotoOAuthSession {
		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw AtprotoOAuthException::invalidResponse('the token response is not valid JSON.');
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw AtprotoOAuthException::invalidResponse('the token response is not a JSON object.');
		}

		$accessToken = $decoded['access_token'] ?? null;
		if (!is_string($accessToken)
			|| $accessToken === ''
			|| trim($accessToken) !== $accessToken
			|| strlen($accessToken) > AtprotoOAuthSession::MAX_TOKEN_LENGTH
		) {
			throw AtprotoOAuthException::invalidResponse('access_token is missing or invalid.');
		}

		$refreshToken = $decoded['refresh_token'] ?? null;
		if (!is_string($refreshToken)
			|| $refreshToken === ''
			|| trim($refreshToken) !== $refreshToken
			|| strlen($refreshToken) > AtprotoOAuthSession::MAX_TOKEN_LENGTH
		) {
			throw AtprotoOAuthException::invalidResponse('refresh_token is missing or invalid.');
		}

		$tokenType = $decoded['token_type'] ?? null;
		if (!is_string($tokenType) || strcasecmp($tokenType, 'DPoP') !== 0) {
			throw AtprotoOAuthException::invalidResponse('token_type must be DPoP.');
		}

		$scope = $decoded['scope'] ?? null;
		if (!is_string($scope)
			|| $scope === ''
			|| trim($scope) !== $scope
			|| strlen($scope) > AtprotoOAuthSession::MAX_SCOPE_LENGTH
			|| !$this->hasAtprotoScope($scope)
		) {
			throw AtprotoOAuthException::invalidResponse('scope is missing or does not contain atproto.');
		}

		$sub = $decoded['sub'] ?? null;
		if (!is_string($sub) || $sub !== $request->did) {
			throw AtprotoOAuthException::invalidResponse('sub does not match the requested DID.');
		}

		$expiresIn = $decoded['expires_in'] ?? null;
		if (!is_int($expiresIn) || $expiresIn < 1 || $expiresIn > 31_536_000) {
			throw AtprotoOAuthException::invalidResponse('expires_in is missing or invalid.');
		}

		return new AtprotoOAuthSession(
			$request->did,
			$request->server->tokenEndpoint,
			$this->clientId,
			$accessToken,
			$refreshToken,
			$scope,
			$this->clock->now()->modify('+' . $expiresIn . ' seconds'),
		);
	}

	private function requestUri(HttpResponse $response): string
	{
		try {
			$decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw AtprotoOAuthException::invalidResponse('the PAR response is not valid JSON.');
		}

		$requestUri = is_array($decoded) ? ($decoded['request_uri'] ?? null) : null;
		if (!is_string($requestUri)
			|| $requestUri === ''
			|| trim($requestUri) !== $requestUri
			|| strlen($requestUri) > 2048
		) {
			throw AtprotoOAuthException::invalidResponse('the PAR response has no request_uri.');
		}

		return $requestUri;
	}

	private function nonce(HttpResponse $response): ?string
	{
		$nonce = $response->header('DPoP-Nonce');

		return is_string($nonce) && trim($nonce) !== '' ? trim($nonce) : null;
	}

	private function errorCode(HttpResponse $response): string
	{
		try {
			$decoded = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return 'oauth_error';
		}

		$error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

		return is_string($error) && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $error) === 1
			? $error
			: 'oauth_error';
	}

	private function hasAtprotoScope(string $scope): bool
	{
		return in_array('atproto', preg_split('/\s+/', $scope) ?: [], true);
	}

	private function randomToken(int $bytes): string
	{
		return $this->base64Url(random_bytes($bytes));
	}

	private function base64Url(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private function isClientId(string $clientId): bool
	{
		if ($clientId === '' || trim($clientId) !== $clientId || strlen($clientId) > AtprotoOAuthSession::MAX_CLIENT_ID_LENGTH) {
			return false;
		}

		$parts = parse_url($clientId);

		return $parts !== false
			&& isset($parts['scheme'], $parts['host'])
			&& (string) $parts['host'] !== ''
			&& in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			&& !isset($parts['user'])
			&& !isset($parts['pass'])
			&& !isset($parts['fragment']);
	}

	private function isRedirectUri(string $redirectUri): bool
	{
		if ($redirectUri === '' || trim($redirectUri) !== $redirectUri || strlen($redirectUri) > AtprotoOAuthSession::MAX_ENDPOINT_LENGTH) {
			return false;
		}

		$parts = parse_url($redirectUri);

		return $parts !== false
			&& isset($parts['scheme'], $parts['host'])
			&& (string) $parts['host'] !== ''
			&& in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			&& !isset($parts['user'])
			&& !isset($parts['pass'])
			&& !isset($parts['fragment']);
	}
}
