<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

use RuntimeException;

/**
 * A request that never produced a response: DNS, TLS, connection or timeout.
 *
 * A request that produced a non-2xx response is not an exception at this
 * layer; the caller decides what an answer means.
 */
class HttpClientException extends RuntimeException
{
}
