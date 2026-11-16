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
 * `GET /api/v1/account/deletion`
 *
 * The durable result of a deletion request. An account that never asked is
 * `active`; a completed deletion stays `erased` with its timestamp, affected
 * areas and blob failures.
 */
final class AccountDeletionStatusHandler implements Handler
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
				'Account deletion status requires the host SQL account-data adapter.',
			);
		}

		return Response::json(
			$this->accountData->deletionStatus($userId)->toArray(),
			200,
			['Cache-Control' => 'private, no-store'],
		);
	}
}
