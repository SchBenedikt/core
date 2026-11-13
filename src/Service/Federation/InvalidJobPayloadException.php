<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Jobs\JobFailureCode;
use AlohaSocial\Core\Jobs\TerminalJobException;
use RuntimeException;

/**
 * A queued delivery does not carry what a delivery needs.
 *
 * Permanent: retrying cannot fix a payload, so the job ends up in the
 * dead-letter count instead of burning attempts.
 */
final class InvalidJobPayloadException extends RuntimeException implements TerminalJobException
{
	public function errorCode(): string
	{
		return JobFailureCode::INVALID_PAYLOAD;
	}
}
