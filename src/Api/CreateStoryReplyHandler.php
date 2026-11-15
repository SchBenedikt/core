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
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

/**
 * `POST /api/v1/stories/{id}/replies`
 */
final class CreateStoryReplyHandler implements Handler
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

		$content = $request->payload()['content'] ?? null;
		if (!is_string($content)) {
			throw HttpException::validationFailed(['content' => 'The reply content must be a string.']);
		}

		try {
			$reply = $this->stories->reply($userId, (string) $request->attribute('id', ''), $content);
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['reply' => $reply],
			201,
			['Cache-Control' => 'private, no-store'],
		);
	}
}
