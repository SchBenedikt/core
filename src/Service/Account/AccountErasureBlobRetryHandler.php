<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Account;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use AlohaSocial\Core\Jobs\TerminalJobException;
use RuntimeException;

/** Retries persisted object-store failures after the account tombstone is committed. */
final class AccountErasureBlobRetryHandler implements JobHandler
{
	public function __construct(private readonly AccountDataService $accounts)
	{
	}

	public function handle(Job $job): void
	{
		$userId = $job->payload['user_id'] ?? null;
		if (!is_string($userId) || trim($userId) === '') {
			throw new InvalidAccountErasureJobException();
		}

		$report = $this->accounts->erase($userId);
		if (!$report->complete()) {
			throw new RuntimeException('Account erasure still has failed blob deletions.');
		}
	}
}

final class InvalidAccountErasureJobException extends RuntimeException implements TerminalJobException
{
	public function __construct()
	{
		parent::__construct('An account erasure retry needs a non-empty user_id.');
	}

	public function errorCode(): string
	{
		return 'invalid_account_erasure_job';
	}
}
