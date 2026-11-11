<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

/**
 * Outbound HTTP for federation.
 *
 * The port exists so that the delivery path can be tested without a network
 * and so that the Nextcloud host can keep using the server's own client (CA
 * bundle, proxy, address guards) instead of shipping a second HTTP stack.
 */
interface HttpClient
{
	/**
	 * @param array<string, string> $headers
	 *
	 * @throws HttpClientException on a transport failure
	 */
	public function request(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
	): HttpResponse;
}
