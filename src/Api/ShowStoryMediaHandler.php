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
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\Story\StoryDisabledException;
use AlohaSocial\Core\Service\Story\StoryMediaUnavailableException;
use AlohaSocial\Core\Service\Story\StoryNotFoundException;
use AlohaSocial\Core\Service\Story\StoryService;

use function strlen;

/**
 * `GET /api/v1/stories/{id}/media`
 *
 * The story access rule is checked before a single byte is read. The response
 * is private and never cacheable by a shared proxy.
 */
final class ShowStoryMediaHandler implements Handler
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
			$media = $this->stories->readMedia($userId, (string) $request->attribute('id', ''));
		} catch (StoryDisabledException $exception) {
			throw new HttpException(503, 'stories_disabled', $exception->getMessage());
		} catch (StoryNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		} catch (StoryMediaUnavailableException $exception) {
			throw HttpException::notFound($exception->getMessage());
		} catch (StorageException) {
			throw HttpException::notFound('Story media not found.');
		}

		$attachment = $media['attachment'];
		$bytes = $media['bytes'];

		return new Response(200, [
			'Content-Type' => $attachment->mimeType,
			'Content-Length' => (string) strlen($bytes),
			'Cache-Control' => 'private, no-store',
			'X-Content-Type-Options' => 'nosniff',
			'Content-Disposition' => 'inline; filename="story-' . $attachment->id . '.' . $attachment->extension() . '"',
		], $bytes);
	}
}
