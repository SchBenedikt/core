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
 * `GET {mastodon.prefix}/mutes`
 */
final class MastodonMutesHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respondAccountList(
			$request,
			fn (int $limit, ?string $maxId, ?string $sinceId, ?string $minId): array
				=> $this->mastodon->mutes(
					(string) $request->userId,
					$limit,
					$maxId,
					$sinceId,
					$minId,
				),
		);
	}
}
