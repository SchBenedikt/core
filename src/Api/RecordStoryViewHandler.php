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
use AlohaSocial\Core\Service\Story\StoryNotFoundException;
use AlohaSocial\Core\Service\Story\StoryService;

/**
 * `POST /api/v1/stories/{id}/views`
 */
final class RecordStoryViewHandler implements Handler
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
			$this->stories->markView($userId, (string) $request->attribute('id', ''));
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		}

		return Response::empty();
	}
}
