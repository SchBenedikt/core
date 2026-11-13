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
 * `GET {mastodon.prefix}/lists/{id}`
 */
final class MastodonListHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(fn (): array => $this->mastodon->list(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
		));
	}
}
