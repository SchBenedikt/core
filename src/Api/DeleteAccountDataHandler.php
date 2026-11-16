<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Account\AccountDataService;

/**
 * `DELETE /api/v1/account/data`
 *
 * Removes every core-owned row and blob for the signed-in account in one
 * database transaction. The host account, credentials and session are not
 * core data (ADR 0006); the host must delete them after this route succeeds.
 */
final class DeleteAccountDataHandler implements Handler
{
	public function __construct(
		private readonly ?AccountDataService $accountData,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		if ($this->accountData === null) {
			throw new HttpException(
				503,
				'account_data_unavailable',
				'Account data erasure requires the host SQL account-data adapter.',
			);
		}

		$report = $this->accountData->erase($userId);
		if (!$report->complete()) {
			throw new HttpException(
				500,
				'account_data_blob_erasure_incomplete',
				'The account rows were removed, but some blob objects could not be deleted.',
			);
		}

		return Response::json($report->toArray(), 200, ['Cache-Control' => 'private, no-store']);
	}
}
