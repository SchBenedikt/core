<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `GET {mastodon.prefix}/accounts/lookup?acct=handle`
 */
final class MastodonAccountLookupHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$acct = $request->queryParam('acct');
		if ($acct === null || trim($acct) === '') {
			throw HttpException::validationFailed(['acct' => 'acct is required.']);
		}

		return $this->respond(fn (): array => $this->mastodon->lookupAccount(
			(string) $request->userId,
			$acct,
		));
	}
}
