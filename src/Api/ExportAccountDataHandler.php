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
 * `GET /api/v1/account/export`
 *
 * Returns the signed-in account's core rows and blob bytes as one JSON
 * archive. The response is private and must never be cached by a proxy.
 */
final class ExportAccountDataHandler implements Handler
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
				'Account data export requires the host SQL account-data adapter.',
			);
		}

		return Response::json(
			$this->accountData->export($userId),
			200,
			[
				'Cache-Control' => 'private, no-store',
				'Content-Disposition' => 'attachment; filename="account-export.json"',
			],
		);
	}
}
