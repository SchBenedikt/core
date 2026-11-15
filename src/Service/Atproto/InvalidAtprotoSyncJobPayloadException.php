<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Jobs\JobFailureCode;
use AlohaSocial\Core\Jobs\TerminalJobException;
use RuntimeException;

/**
 * An AT Protocol sync job carries a limit the service cannot accept.
 *
 * Retrying cannot fix the payload, so the worker dead-letters it immediately.
 */
final class InvalidAtprotoSyncJobPayloadException extends RuntimeException implements TerminalJobException
{
	public function errorCode(): string
	{
		return JobFailureCode::INVALID_PAYLOAD;
	}
}
