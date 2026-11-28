<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostIdempotencyConflictException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Service\Mastodon\MastodonScheduledStatusPresenter;
use AlohaSocial\Core\Service\Mastodon\MastodonService;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

use function is_array;
use function is_bool;
use function is_string;

/**
 * `POST {mastodon.prefix}/statuses`
 *
 * Accepts the JSON and form encodings Mastodon clients use. `private` maps to
 * the core's followers-only visibility and `direct` uses the local recipient
 * model. Direct recipients come from the `recipient_ids` extension or from
 * `@local-id` mentions in the status text. `Idempotency-Key` becomes a
 * deterministic post id, so a retry returns the same status instead of
 * creating a second post.
 */
final class MastodonCreateStatusHandler extends MastodonHandler
{
	public function __construct(
		MastodonService $mastodon,
		private readonly ?ScheduledPostService $scheduled = null,
		private readonly ?MastodonScheduledStatusPresenter $scheduledPresenter = null,
	) {
		parent::__construct($mastodon);
	}

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
		$language = $body['language'] ?? null;
		if ($language !== null && !is_string($language)) {
			throw new MastodonRequestException(422, 'validation_failed', 'language must be a string or null.');
		}

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

		$inReplyToId = $body['in_reply_to_id'] ?? null;
		if ($inReplyToId !== null && !is_string($inReplyToId)) {
			throw new MastodonRequestException(422, 'validation_failed', 'in_reply_to_id must be a string or null.');
		}

		$inReplyToId = $inReplyToId === null || trim($inReplyToId) === '' ? null : trim($inReplyToId);

		// Mastodon 4.5 clients send the quoted status id. Older clients never
		// set it, so the absence is not a compatibility problem.
		$quotedStatusId = $body['quoted_status_id'] ?? null;
		if ($quotedStatusId !== null && !is_string($quotedStatusId)) {
			throw new MastodonRequestException(422, 'validation_failed', 'quoted_status_id must be a string or null.');
		}

		$quotedStatusId = $quotedStatusId === null || trim($quotedStatusId) === '' ? null : trim($quotedStatusId);

		$recipientIds = $this->recipientIds($body['recipient_ids'] ?? []);
		if ($visibility === Visibility::Direct && $recipientIds === []) {
			$recipientIds = $this->mentionedLocalIds($content);
		}

		$poll = $body['poll'] ?? null;
		if ($poll !== null && !is_array($poll)) {
			throw new MastodonRequestException(422, 'validation_failed', 'poll must be an object.');
		}

		$idempotencyKey = trim((string) $request->header('idempotency-key'));

		// A Mastodon client schedules by sending the same write to the status
		// route with `scheduled_at`. It is stored, not published: the status
		// route must not return a status id before the publication time.
		$rawScheduledAt = $body['scheduled_at'] ?? null;
		if ($rawScheduledAt !== null) {
			if (!is_string($rawScheduledAt) || trim($rawScheduledAt) === '') {
				throw new MastodonRequestException(422, 'validation_failed', 'scheduled_at must be a timestamp or null.');
			}

			if ($this->scheduled === null || $this->scheduledPresenter === null) {
				throw new MastodonRequestException(422, 'validation_failed', 'Scheduling is not configured.');
			}

			if ($quotedStatusId !== null) {
				throw new MastodonRequestException(422, 'validation_failed', 'A scheduled status cannot quote another status.');
			}

			try {
				$scheduledAt = Time::parseIso8601($rawScheduledAt);
			} catch (InvalidArgumentException) {
				throw new MastodonRequestException(422, 'validation_failed', 'scheduled_at must be an ISO 8601 timestamp.');
			}

			$scheduledId = $idempotencyKey === ''
				? null
				: 'mastodon-scheduled-' . substr(hash('sha256', $userId . "\0" . $idempotencyKey), 0, 32);

			$pollSpec = $poll === null ? null : $this->mastodon->scheduledPollSpec($poll);

			return $this->respond(function () use ($userId, $content, $visibility, $spoiler, $ids, $inReplyToId, $recipientIds, $language, $scheduledAt, $scheduledId, $pollSpec): array {
				try {
					$scheduled = $this->scheduled->schedule(
						$userId,
						new CreatePost(
							authorId: $userId,
							content: $content,
							visibility: $visibility,
							contentWarning: $spoiler === null || trim($spoiler) === '' ? null : trim($spoiler),
							attachmentIds: $ids,
							inReplyToId: $inReplyToId,
							recipientIds: $recipientIds,
							language: $language,
						),
						$scheduledAt,
						$scheduledId,
						$pollSpec,
					);
				} catch (ScheduledPostIdempotencyConflictException) {
					throw new MastodonRequestException(
						409,
						'idempotency_conflict',
						'This Idempotency-Key was already used for a different scheduled status.',
					);
				}

				return $this->scheduledPresenter->present($scheduled);
			});
		}

		return $this->respond(fn (): array => $this->mastodon->createStatus(
			$userId,
			$content,
			$visibility,
			$spoiler === null || trim($spoiler) === '' ? null : trim($spoiler),
			$ids,
			$idempotencyKey === '' ? null : $idempotencyKey,
			$poll,
			$inReplyToId,
			$recipientIds,
			$quotedStatusId,
			$language,
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
			'direct' => Visibility::Direct,
			default => throw new MastodonRequestException(
				422,
				'validation_failed',
				'visibility must be one of public, unlisted, private or direct.',
			),
		};
	}

	/**
	 * @return list<string>
	 */
	private function recipientIds(mixed $value): array
	{
		if (!is_array($value) || !array_is_list($value)) {
			throw new MastodonRequestException(422, 'validation_failed', 'recipient_ids must be a list.');
		}

		$ids = [];
		foreach ($value as $id) {
			if (!is_string($id) || trim($id) === '') {
				throw new MastodonRequestException(422, 'validation_failed', 'recipient_ids must contain strings.');
			}

			$ids[] = trim($id);
		}

		return $ids;
	}

	/**
	 * Local handles are the only mentions the core can address. A full
	 * `@user@remote.example` handle is deliberately not reduced to `user`:
	 * that would silently deliver a direct post to a different local account.
	 *
	 * @return list<string>
	 */
	private function mentionedLocalIds(string $content): array
	{
		preg_match_all('/(?<![\w.@])@([A-Za-z0-9_][A-Za-z0-9_.-]*)(?![\w.@-])/u', $content, $matches);

		return array_values(array_unique($matches[1] ?? []));
	}
}
