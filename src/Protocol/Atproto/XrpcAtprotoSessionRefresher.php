<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefreshException;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefresher;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use JsonException;

use function array_is_list;
use function http_build_query;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_split;
use function strcasecmp;
use function strlen;
use function trim;

/**
 * Refreshes an AT Protocol OAuth session at the authorization server.
 *
 * The authorization server, not necessarily the PDS, owns the token endpoint.
 * The request is form-encoded and carries a fresh DPoP proof; a server nonce
 * is reused for exactly one retry. A successful response rotates both tokens,
 * so the caller must persist the returned session.
 */
final class XrpcAtprotoSessionRefresher implements AtprotoSessionRefresher
{
	public const TIMEOUT_SECONDS = 20;

	public function __construct(
		private readonly HttpClient $http,
		private readonly DpopProofProvider $dpop,
		private readonly Clock $clock,
	) {
	}

	public function refresh(AtprotoOAuthSession $session): AtprotoOAuthSession
	{
		$body = http_build_query([
			'grant_type' => 'refresh_token',
			'refresh_token' => $session->refreshToken,
			'client_id' => $session->clientId,
		], '', '&', PHP_QUERY_RFC3986);

		$nonce = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$response = $this->http->request(
					'POST',
					$session->tokenEndpoint,
					[
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Accept' => 'application/json',
						'DPoP' => $this->dpop->proof('POST', $session->tokenEndpoint, $nonce),
					],
					$body,
					self::TIMEOUT_SECONDS,
				);
			} catch (HttpClientException) {
				throw AtprotoSessionRefreshException::transportFailure();
			}

			$nextNonce = $response->header('DPoP-Nonce');
			$nextNonce = is_string($nextNonce) && trim($nextNonce) !== '' ? trim($nextNonce) : null;

			if ($response->isSuccessful()) {
				if ($nextNonce === null) {
					throw AtprotoSessionRefreshException::missingNonce();
				}

				return $this->parseSuccess($session, $response);
			}

			$errorCode = $this->errorCode($response);
			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce !== null && $attempt === 0) {
				$nonce = $nextNonce;
				continue;
			}

			if ($errorCode === 'use_dpop_nonce' && $response->status === 401 && $nextNonce === null) {
				throw AtprotoSessionRefreshException::missingNonce();
			}

			throw AtprotoSessionRefreshException::refused(
				$response->status,
				$errorCode,
				$response->status === 429 || $response->status >= 500,
			);
		}

		throw AtprotoSessionRefreshException::refused(401, 'use_dpop_nonce', false);
	}

	private function parseSuccess(AtprotoOAuthSession $session, HttpResponse $response): AtprotoOAuthSession
	{
		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw AtprotoSessionRefreshException::invalidResponse('the body is not valid JSON');
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw AtprotoSessionRefreshException::invalidResponse('the body is not a JSON object');
		}

		$accessToken = $decoded['access_token'] ?? null;
		if (!is_string($accessToken)
			|| $accessToken === ''
			|| trim($accessToken) !== $accessToken
			|| strlen($accessToken) > AtprotoOAuthSession::MAX_TOKEN_LENGTH
		) {
			throw AtprotoSessionRefreshException::invalidResponse('access_token is missing or invalid');
		}

		$refreshToken = $decoded['refresh_token'] ?? null;
		if (!is_string($refreshToken)
			|| $refreshToken === ''
			|| trim($refreshToken) !== $refreshToken
			|| strlen($refreshToken) > AtprotoOAuthSession::MAX_TOKEN_LENGTH
		) {
			throw AtprotoSessionRefreshException::invalidResponse('refresh_token is missing or invalid');
		}

		$tokenType = $decoded['token_type'] ?? null;
		if (!is_string($tokenType) || strcasecmp($tokenType, 'DPoP') !== 0) {
			throw AtprotoSessionRefreshException::invalidResponse('token_type must be DPoP');
		}

		$scope = $decoded['scope'] ?? null;
		if (!is_string($scope)
			|| $scope === ''
			|| trim($scope) !== $scope
			|| strlen($scope) > AtprotoOAuthSession::MAX_SCOPE_LENGTH
			|| !$this->hasAtprotoScope($scope)
		) {
			throw AtprotoSessionRefreshException::invalidResponse('scope is missing or does not contain atproto');
		}

		$sub = $decoded['sub'] ?? null;
		if (!is_string($sub) || $sub !== $session->did) {
			throw AtprotoSessionRefreshException::invalidResponse('sub does not match the session DID');
		}

		$expiresIn = $decoded['expires_in'] ?? null;
		if (!is_int($expiresIn) || $expiresIn < 1 || $expiresIn > 31_536_000) {
			throw AtprotoSessionRefreshException::invalidResponse('expires_in is missing or invalid');
		}

		$expiresAt = $this->clock->now()->modify('+' . $expiresIn . ' seconds');

		return $session->withRotatedTokens($accessToken, $refreshToken, $scope, $expiresAt);
	}

	private function hasAtprotoScope(string $scope): bool
	{
		return in_array('atproto', preg_split('/\s+/', $scope) ?: [], true);
	}

	private function errorCode(HttpResponse $response): string
	{
		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return 'oauth_error';
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			return 'oauth_error';
		}

		$error = $decoded['error'] ?? null;

		return is_string($error) && $error !== '' && strlen($error) <= 64 ? $error : 'oauth_error';
	}
}
