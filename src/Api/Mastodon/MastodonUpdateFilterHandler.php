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
 * `PUT {mastodon.prefix}/filters/{id}`
 */
final class MastodonUpdateFilterHandler extends MastodonFilterHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$input = $this->input($request);

			return $this->presenter->present($this->filters->update(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
				$input['name'],
				$input['action'],
				$input['contexts'],
				$input['keywords'],
				$input['expiresAt'],
			));
		});
	}
}
