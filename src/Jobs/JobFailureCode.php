<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

/**
 * Stable reason codes for worker failures.
 *
 * These are log/operator codes, not HTTP response codes. They must not change
 * once documented: dashboards and runbooks match on them.
 */
final class JobFailureCode
{
	public const INVALID_TYPE = 'invalid_job_type';

	public const INVALID_PAYLOAD = 'invalid_job_payload';

	public const UNKNOWN_TYPE = 'unknown_job_type';

	public const HANDLER_FAILED = 'job_handler_failed';

	public const COMPLETION_FAILED = 'job_completion_failed';

	private function __construct()
	{
	}
}
