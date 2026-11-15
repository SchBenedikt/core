<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Story\StoryDisabledException;
use AlohaSocial\Core\Service\Story\StoryForbiddenException;
use AlohaSocial\Core\Service\Story\StoryNotFoundException;
use AlohaSocial\Core\Service\Story\StoryService;

/**
 * `GET /api/v1/stories/{id}/viewers`
 *
 * Only the owner can read the receipt list; every other reader gets the same
 * 404 as for an unknown story, so the endpoint cannot be used to probe ids.
 */
final class StoryViewersHandler implements Handler
{
	public function __construct(
		private readonly StoryService $stories,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$viewers = $this->stories->viewers($userId, (string) $request->attribute('id', ''));
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryForbiddenException|StoryNotFoundException) {
			throw HttpException::notFound('Story not found.');
		}

		return Response::json(['viewers' => $viewers], 200, ['Cache-Control' => 'private, no-store']);
	}
}
