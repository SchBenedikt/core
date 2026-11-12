<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

/**
 * A response was larger than the caller allowed.
 *
 * This is separate from {@see HttpClientException} so the media importer can
 * report a size failure rather than a generic connection failure.
 */
final class ResponseLimitExceededException extends HttpClientException
{
}
