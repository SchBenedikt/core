<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ReactionException;

/**
 * `GET /api/v1/remote-posts/{id}`
 *
 * `id` is the remote object id and therefore a URL; clients percent-encode it
 * as one path segment. The projection includes the viewer's own reaction
 * state, which is what makes the neutral API and the Mastodon status shape
 * agree about what this account did.
 */
final class ShowRemotePostHandler extends RemoteReactionHandler
{
	public function handle(Request $request): Response
	{
		$userId = $this->userId($request);
		$reactions = $this->service();

		try {
			$post = $reactions->remotePostFor($userId, (string) $request->attribute('id', ''));
		} catch (ReactionException $exception) {
			throw $this->map($exception);
		}

		return Response::json([
			'post' => $post->toArray(),
			'interaction' => $reactions->remoteInteractionsFor($userId, $post),
		]);
	}
}
