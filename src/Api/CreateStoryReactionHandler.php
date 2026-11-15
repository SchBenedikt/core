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

use function is_string;

/**
 * `POST /api/v1/stories/{id}/reactions`
 */
final class CreateStoryReactionHandler implements Handler
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

		$emoji = $request->payload()['emoji'] ?? null;
		if (!is_string($emoji) || trim($emoji) === '') {
			throw HttpException::validationFailed(['emoji' => 'An emoji is required.']);
		}

		try {
			$reaction = $this->stories->react($userId, (string) $request->attribute('id', ''), $emoji);
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		}

		return Response::json(['reaction' => $reaction], 201, ['Cache-Control' => 'private, no-store']);
	}
}
