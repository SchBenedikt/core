<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;

/** `GET {mastodon.prefix}/follow_requests` */
final class MastodonFollowRequestsHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		if ($request->queryParam('min_id') !== null) {
			throw new MastodonRequestException(
				422,
				'unsupported_pagination',
				'min_id pagination is not supported for follow requests.',
			);
		}

		return $this->respondAccountList(
			$request,
			fn (int $limit, ?string $maxId, ?string $sinceId, ?string $_minId): array
				=> $this->mastodon->followRequests((string) $request->userId, $limit, $maxId, $sinceId),
		);
	}
}
