<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * A queued AT Protocol publication is missing its account or post id.
 *
 * Retrying cannot fix the payload, so the worker moves it to the dead-letter
 * count instead of spending the remaining attempts.
 */
final class InvalidAtprotoJobPayloadException extends RuntimeException
{
}
