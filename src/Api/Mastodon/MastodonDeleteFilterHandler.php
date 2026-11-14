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
 * `DELETE {mastodon.prefix}/filters/{id}`
 */
final class MastodonDeleteFilterHandler extends MastodonFilterHandler
{
	public function handle(Request $request): Response
	{
		return $this->respondEmpty(fn (): mixed => $this->filters->delete(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
		));
	}
}
