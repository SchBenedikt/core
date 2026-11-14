<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Creates a DPoP proof bound to one access token for an authenticated PDS
 * request (RFC 9449 `ath` claim).
 *
 * AT Protocol OAuth access tokens are DPoP-bound: a PDS refuses a bearer
 * request even when the token itself is valid. A provider that only signs token
 * requests (`DpopProofProvider`) cannot authenticate a record write, so the
 * resource adapters ask for this capability explicitly.
 */
interface DpopResourceProofProvider
{
	/**
	 * @param string|null $nonce the PDS-issued DPoP nonce, if one is known
	 *
	 * @throws \InvalidArgumentException when the method, URL, token or nonce is invalid
	 */
	public function proofForResource(
		string $method,
		string $url,
		string $accessToken,
		?string $nonce = null,
	): string;
}
