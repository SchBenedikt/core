<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Creates a fresh DPoP proof JWT for one token-endpoint request.
 *
 * The host keeps the private ES256 key; this collaborator keeps the protocol
 * proof construction out of the refresh transport and easy to test.
 */
interface DpopProofProvider
{
	/**
	 * @param string|null $nonce the server-issued DPoP nonce, if one is known
	 *
	 * @throws \InvalidArgumentException when the method, URL or nonce is invalid
	 */
	public function proof(string $method, string $url, ?string $nonce = null): string;
}
