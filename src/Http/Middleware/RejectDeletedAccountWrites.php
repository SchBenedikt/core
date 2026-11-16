<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http\Middleware;

use AlohaSocial\Core\Domain\Account\AccountDeletionSweeper;
use AlohaSocial\Core\Domain\Account\AccountWriteGuard;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Middleware;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Http\Route;

/**
 * Stops a frozen or erased account from writing through the API.
 *
 * Reads stay available so the status and export remain inspectable. The
 * deletion route itself must stay reachable: that is what makes a repeated
 * DELETE idempotent instead of a 403.
 */
final class RejectDeletedAccountWrites implements Middleware
{
	public function __construct(
		private readonly ?AccountWriteGuard $guard,
		private readonly ?AccountDeletionSweeper $sweeper = null,
	) {
	}

	public function process(Request $request, callable $next): Response
	{
		if ($this->guard === null || $request->userId === null) {
			return $next($request);
		}

		if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
			return $next($request);
		}

		$route = $request->attribute('route');
		if ($route instanceof Route && $route->name === 'account.data.delete') {
			return $next($request);
		}

		$status = $this->guard->accountWriteStatus($request->userId);
		if ($status === null) {
			try {
				return $next($request);
			} finally {
				$this->sweepIfFrozen($request);
			}
		}

		if ($status === 410) {
			throw new HttpException(
				410,
				'account_deleted',
				'This account has been deleted; no further writes are accepted.',
			);
		}

		throw new HttpException(
			403,
			'account_deletion_in_progress',
			'This account is being deleted; writes are blocked.',
		);
	}

	/**
	 * A request that passed the pre-check just before the freeze can commit
	 * while the deletion is running. Sweep that late write before responding.
	 */
	private function sweepIfFrozen(Request $request): void
	{
		if ($this->sweeper === null || $request->userId === null) {
			return;
		}

		if ($this->guard?->accountWriteStatus($request->userId) !== null) {
			$this->sweeper->sweep($request->userId);
		}
	}
}
