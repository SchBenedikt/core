<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use stdClass;

/**
 * `DELETE {mastodon.prefix}/media/{id}`
 *
 * Mastodon answers with an empty JSON object, not a 204.
 */
final class MastodonDeleteMediaHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respondEmptyObject(function () use ($request): void {
			$this->mastodon->deleteMedia(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
			);
		});
	}
}
