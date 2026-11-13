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
 * `POST {mastodon.prefix}/statuses/{id}/unreblog`
 */
final class MastodonUnreblogStatusHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(fn (): array => $this->mastodon->unreblogStatus(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
		));
	}
}
