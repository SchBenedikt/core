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
 * `DELETE /api/v1/remote-posts/{id}/like`
 *
 * Removing an absent like is a successful no-op and sends nothing, so a
 * retried unlike cannot produce a stray Undo.
 */
final class UnlikeRemotePostHandler extends RemoteReactionHandler
{
	public function handle(Request $request): Response
	{
		$userId = $this->userId($request);
		$reactions = $this->service();
		$id = (string) $request->attribute('id', '');

		return $this->interaction(fn (): array => $reactions->unlikeRemote($userId, $id));
	}
}
