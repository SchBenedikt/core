<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;

use function is_array;
use function is_bool;
use function is_string;

/**
 * `POST {mastodon.prefix}/statuses`
 *
 * Accepts the JSON and form encodings Mastodon clients use. `private` maps to
 * the core's followers-only visibility; `direct` is refused because the core
 * has no recipient model yet. `Idempotency-Key` becomes a deterministic post
 * id, so a retry returns the same status instead of creating a second post.
 */
final class MastodonCreateStatusHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$body = $request->payload();
		$userId = (string) $request->userId;

		$content = $body['status'] ?? null;
		if (!is_string($content)) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'The status field is required and must be a string.',
			);
		}

		$spoiler = $body['spoiler_text'] ?? null;
		if ($spoiler !== null && !is_string($spoiler)) {
			throw new MastodonRequestException(422, 'validation_failed', 'spoiler_text must be a string.');
		}

		$sensitive = $body['sensitive'] ?? false;
		if (!is_bool($sensitive) && !in_array($sensitive, [0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)) {
			throw new MastodonRequestException(422, 'validation_failed', 'sensitive must be a boolean.');
		}

		if (in_array($sensitive, [true, 1, '1', 'true', 'on'], true) && ($spoiler === null || trim($spoiler) === '')) {
			$spoiler = 'Sensitive';
		}

		$visibility = $this->visibility($body['visibility'] ?? 'public');

		$mediaIds = $body['media_ids'] ?? [];
		if (!is_array($mediaIds) || !array_is_list($mediaIds)) {
			throw new MastodonRequestException(422, 'validation_failed', 'media_ids must be a list.');
		}

		$ids = [];
		foreach ($mediaIds as $mediaId) {
			if (!is_string($mediaId) || trim($mediaId) === '') {
				throw new MastodonRequestException(422, 'validation_failed', 'media_ids must contain strings.');
			}

			$ids[] = $mediaId;
		}

		if (($body['in_reply_to_id'] ?? null) !== null && $body['in_reply_to_id'] !== '') {
			throw new MastodonRequestException(
				422,
				'unsupported_parameter',
				'in_reply_to_id is not available in this compatibility slice.',
			);
		}

		$poll = $body['poll'] ?? null;
		if ($poll !== null && !is_array($poll)) {
			throw new MastodonRequestException(422, 'validation_failed', 'poll must be an object.');
		}

		$idempotencyKey = trim((string) $request->header('idempotency-key'));

		return $this->respond(fn (): array => $this->mastodon->createStatus(
			$userId,
			$content,
			$visibility,
			$spoiler === null || trim($spoiler) === '' ? null : trim($spoiler),
			$ids,
			$idempotencyKey === '' ? null : $idempotencyKey,
			$poll,
		));
	}

	private function visibility(mixed $value): Visibility
	{
		if (!is_string($value)) {
			throw new MastodonRequestException(422, 'validation_failed', 'visibility must be a string.');
		}

		return match (strtolower(trim($value))) {
			'public' => Visibility::Public,
			'unlisted' => Visibility::Unlisted,
			'private' => Visibility::Followers,
			'direct' => throw new MastodonRequestException(
				422,
				'unsupported_parameter',
				'direct visibility is not available in this compatibility slice.',
			),
			default => throw new MastodonRequestException(
				422,
				'validation_failed',
				'visibility must be one of public, unlisted, private or direct.',
			),
		};
	}
}
