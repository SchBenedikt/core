<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Api\MediaUpdateInput;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `PUT {mastodon.prefix}/media/{id}`
 */
final class MastodonUpdateMediaHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$input = MediaUpdateInput::fromRequest($request);

		return $this->respond(fn (): array => $this->mastodon->updateMedia(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
			$input->hasDescription,
			$input->description,
			$input->hasFocus,
			$input->focusX,
			$input->focusY,
		));
	}
}
