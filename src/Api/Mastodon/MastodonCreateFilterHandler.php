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
 * `POST {mastodon.prefix}/filters`
 */
final class MastodonCreateFilterHandler extends MastodonFilterHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$input = $this->input($request);

			return $this->presenter->present($this->filters->create(
				(string) $request->userId,
				$input['name'],
				$input['action'],
				$input['contexts'],
				$input['keywords'],
				$input['expiresAt'],
			));
		});
	}
}
