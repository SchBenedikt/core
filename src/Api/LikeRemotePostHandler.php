<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `POST /api/v1/remote-posts/{id}/like`
 *
 * The local reaction is stored before the delivery job is queued; a repeated
 * call is idempotent and does not enqueue a second Like.
 */
final class LikeRemotePostHandler extends RemoteReactionHandler
{
	public function handle(Request $request): Response
	{
		$userId = $this->userId($request);
		$reactions = $this->service();
		$id = (string) $request->attribute('id', '');

		return $this->interaction(fn (): array => $reactions->likeRemote($userId, $id));
	}
}
