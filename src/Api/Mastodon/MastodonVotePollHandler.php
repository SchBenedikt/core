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

use function is_array;

/**
 * `POST {mastodon.prefix}/polls/{id}/votes`
 *
 * Accepts the JSON and form encodings Mastodon clients use. The poll id is
 * derived from the post id; a visible single-choice poll accepts one position,
 * a multiple-choice poll accepts one or more. A repeated identical vote is a
 * successful no-op, while a different second vote is `422 already_voted`.
 */
final class MastodonVotePollHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$body = $request->payload();
		$choices = $body['choices'] ?? null;
		if (!is_array($choices)) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'choices must be a list of poll option positions.',
			);
		}

		return $this->respond(fn (): array => $this->mastodon->votePoll(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
			$choices,
		));
	}
}
