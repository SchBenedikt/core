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
 * `POST {mastodon.prefix}/lists`
 */
final class MastodonCreateListHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(fn (): array => $this->mastodon->createList(
			(string) $request->userId,
			$this->optionalPayloadString($request, 'title'),
			$this->optionalPayloadString($request, 'replies_policy'),
			$this->optionalPayloadBoolean($request, 'exclusive'),
		));
	}
}
