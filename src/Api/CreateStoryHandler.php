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
use AlohaSocial\Core\Service\Story\StoryMediaUnavailableException;
use AlohaSocial\Core\Service\Story\StoryService;
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

/**
 * `POST /api/v1/stories`
 */
final class CreateStoryHandler implements Handler
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

		$input = $request->payload();
		$attachmentId = $input['attachment_id'] ?? null;
		$caption = $input['caption'] ?? '';
		$fields = [];
		if (!is_string($attachmentId) || trim($attachmentId) === '') {
			$fields['attachment_id'] = 'A story needs an attachment id.';
		}

		if (!is_string($caption)) {
			$fields['caption'] = 'The caption must be a string.';
		}

		if ($fields !== []) {
			throw HttpException::validationFailed($fields);
		}

		try {
			$story = $this->stories->create($userId, $attachmentId, $caption);
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryMediaUnavailableException $exception) {
			throw new HttpException(503, 'story_media_unavailable', $exception->getMessage());
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['story' => $this->stories->show($userId, $story->id)],
			201,
			['Location' => '/api/v1/stories/' . rawurlencode($story->id), 'Cache-Control' => 'private, no-store'],
		);
	}
}
