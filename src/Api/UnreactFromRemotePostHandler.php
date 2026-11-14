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

use function trim;

/**
 * `DELETE /api/v1/remote-posts/{id}/reactions/{emoji}`
 *
 * The emoji is percent-encoded in the path. Removing an absent reaction is a
 * successful no-op and sends nothing.
 */
final class UnreactFromRemotePostHandler extends RemoteReactionHandler
{
	public function handle(Request $request): Response
	{
		$userId = $this->userId($request);
		$reactions = $this->service();
		$id = (string) $request->attribute('id', '');
		$emoji = (string) $request->attribute('emoji', '');

		if (trim($emoji) === '') {
			throw HttpException::validationFailed(
				['emoji' => 'An emoji is required.'],
				'The reaction needs an emoji.',
			);
		}

		return $this->interaction(fn (): array => $reactions->unreactFromRemote($userId, $id, $emoji));
	}
}
