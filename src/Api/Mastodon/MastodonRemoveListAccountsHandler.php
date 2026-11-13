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
 * `DELETE {mastodon.prefix}/lists/{id}/accounts`
 */
final class MastodonRemoveListAccountsHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$accountIds = $this->payloadIds($request, 'account_ids');

		return $this->respondEmptyObject(function () use ($request, $accountIds): void {
			$this->mastodon->removeListAccounts(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
				$accountIds,
			);
		});
	}
}
