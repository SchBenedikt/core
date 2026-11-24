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

use function array_key_exists;
use function array_is_list;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function trim;

/**
 * `PUT {mastodon.prefix}/statuses/{id}`
 *
 * Mastodon's status edit: text, spoiler text and media. Visibility and reply
 * target are not editable there, and they are not here either — the neutral
 * `PATCH /api/v1/posts/{id}` is the route that can narrow an audience.
 */
final class MastodonUpdateStatusHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$body = $request->payload();

		$content = $body['status'] ?? null;
		if (!is_string($content)) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'The status field is required and must be a string.',
			);
		}

		$spoilerProvided = array_key_exists('spoiler_text', $body);
		$spoiler = $body['spoiler_text'] ?? null;
		if ($spoiler !== null && !is_string($spoiler)) {
			throw new MastodonRequestException(422, 'validation_failed', 'spoiler_text must be a string or null.');
		}

		$sensitive = $body['sensitive'] ?? false;
		if (!is_bool($sensitive) && !in_array($sensitive, [0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)) {
			throw new MastodonRequestException(422, 'validation_failed', 'sensitive must be a boolean.');
		}

		if (in_array($sensitive, [true, 1, '1', 'true', 'on'], true) && ($spoiler === null || trim($spoiler) === '')) {
			$spoiler = 'Sensitive';
			$spoilerProvided = true;
		}

		$languageProvided = array_key_exists('language', $body);
		$language = $body['language'] ?? null;
		if ($languageProvided && $language !== null && !is_string($language)) {
			throw new MastodonRequestException(422, 'validation_failed', 'language must be a string or null.');
		}

		$mediaIds = null;
		if (array_key_exists('media_ids', $body)) {
			$raw = $body['media_ids'];
			if (!is_array($raw) || !array_is_list($raw)) {
				throw new MastodonRequestException(422, 'validation_failed', 'media_ids must be a list.');
			}

			$mediaIds = [];
			foreach ($raw as $mediaId) {
				if (!is_string($mediaId) || trim($mediaId) === '') {
					throw new MastodonRequestException(422, 'validation_failed', 'media_ids must contain strings.');
				}

				$mediaIds[] = $mediaId;
			}
		}

		return $this->respond(fn (): array => $this->mastodon->updateStatus(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
			$content,
			$spoilerProvided,
			$spoiler === null || trim($spoiler) === '' ? null : trim($spoiler),
			$mediaIds,
			$language,
			$languageProvided,
		));
	}
}
