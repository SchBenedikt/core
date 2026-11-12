<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

/**
 * An outbound HTTP client with a hard response-body limit.
 *
 * The caller validates the destination before calling this method. Passing the
 * validated IP addresses lets a client pin the connection to the address that
 * was checked, instead of resolving the hostname a second time (DNS
 * rebinding). A client that cannot support either guarantee cannot be used for
 * remote media fetching.
 */
interface BoundedHttpClient extends HttpClient
{
	/**
	 * @param array<string, string> $headers
	 * @param list<string>          $resolvedIps public addresses the caller already validated
	 *
	 * @throws ResponseLimitExceededException when the body exceeds $maxBytes
	 * @throws HttpClientException           on a transport failure
	 */
	public function requestBounded(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
		int $maxBytes = 1_048_576,
		array $resolvedIps = [],
	): HttpResponse;
}
