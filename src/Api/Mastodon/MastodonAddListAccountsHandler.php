<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `POST {mastodon.prefix}/lists/{id}/accounts`
 */
final class MastodonAddListAccountsHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$accountIds = $this->payloadIds($request, 'account_ids');

		return $this->respondEmptyObject(function () use ($request, $accountIds): void {
			$this->mastodon->addListAccounts(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
				$accountIds,
			);
		});
	}
}
