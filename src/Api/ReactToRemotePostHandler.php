<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

use function is_string;
use function trim;

/**
 * `POST /api/v1/remote-posts/{id}/reactions`
 *
 * The body is `{"emoji": "❤"}`. Only a Unicode emoji is accepted: the core has
 * no verified local custom-emoji source, so it never sends a shortcode whose
 * image it cannot vouch for.
 */
final class ReactToRemotePostHandler extends RemoteReactionHandler
{
	public function handle(Request $request): Response
	{
		$userId = $this->userId($request);
		$reactions = $this->service();
		$id = (string) $request->attribute('id', '');

		$emoji = $request->payload()['emoji'] ?? null;
		if (!is_string($emoji) || trim($emoji) === '') {
			throw HttpException::validationFailed(
				['emoji' => 'An emoji is required.'],
				'The reaction needs an emoji.',
			);
		}

		return $this->interaction(fn (): array => $reactions->reactToRemote($userId, $id, $emoji));
	}
}
