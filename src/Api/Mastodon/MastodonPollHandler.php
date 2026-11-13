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
 * `GET {mastodon.prefix}/polls/{id}`
 *
 * The id is the stable `poll-<postId>` projection returned with a status. A
 * request with an authenticated viewer also includes `voted` and `own_votes`.
 */
final class MastodonPollHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(fn (): array => $this->mastodon->showPoll(
			(string) $request->attribute('id', ''),
			$request->userId,
		));
	}
}
