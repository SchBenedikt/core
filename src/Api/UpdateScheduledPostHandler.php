<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPoll;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostConflictException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_string;
use function trim;

/**
 * `PATCH /api/v1/scheduled-posts/{id}`
 *
 * Accepts the same write fields as `POST /scheduled-posts`; every field that
 * is absent keeps its stored value, `poll: null` removes the poll and a new
 * `scheduled_at` moves the publication. The edit is a compare-and-set
 * against a still-pending row: once a worker claimed it, the write is
 * refused instead of racing the publication.
 */
final class UpdateScheduledPostHandler implements Handler
{
	public function __construct(
		private readonly ScheduledPostService $scheduled,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();
		$id = (string) $request->attribute('id', '');

		try {
			$existing = $this->scheduled->findOwned($userId, $id);
		} catch (ScheduledPostNotFoundException) {
			throw HttpException::notFound('Scheduled post not found.');
		}

		$content = $existing->content;
		if (array_key_exists('content', $body)) {
			if (!is_string($body['content'])) {
				throw HttpException::validationFailed(['content' => 'content must be a string.']);
			}
			$content = $body['content'];
		}

		$contentWarning = $this->nullableString($body, 'content_warning', $existing->contentWarning);
		$language = $this->nullableString($body, 'language', $existing->language);

		$attachmentIds = array_key_exists('attachment_ids', $body)
			? $this->stringList($body['attachment_ids'], 'attachment_ids')
			: $existing->attachmentIds;
		$recipientIds = array_key_exists('recipient_ids', $body)
			? $this->stringList($body['recipient_ids'], 'recipient_ids')
			: $existing->recipientIds;

		$inReplyToId = $existing->inReplyToId;
		if (array_key_exists('in_reply_to_id', $body)) {
			$inReplyToId = $this->nullableString($body, 'in_reply_to_id', null);
			if ($inReplyToId !== null && trim($inReplyToId) === '') {
				throw HttpException::validationFailed(['in_reply_to_id' => 'in_reply_to_id must be a non-empty string or null.']);
			}
		}

		$visibility = $existing->visibility;
		if (array_key_exists('visibility', $body)) {
			$rawVisibility = $body['visibility'];
			if (!is_string($rawVisibility)) {
				throw HttpException::validationFailed(['visibility' => 'visibility must be a string.']);
			}

			try {
				$visibility = Visibility::fromString($rawVisibility);
			} catch (Throwable) {
				throw HttpException::validationFailed(['visibility' => 'visibility is not a supported value.']);
			}
		}

		$poll = $existing->poll;
		if (array_key_exists('poll', $body)) {
			$poll = $this->pollFromInput($body['poll']);
		}

		$scheduledAt = null;
		if (array_key_exists('scheduled_at', $body)) {
			$rawScheduledAt = $body['scheduled_at'];
			if (!is_string($rawScheduledAt) || trim($rawScheduledAt) === '') {
				throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at must be an ISO 8601 timestamp.']);
			}

			try {
				$scheduledAt = Time::parseIso8601($rawScheduledAt);
			} catch (InvalidArgumentException) {
				throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at must be an ISO 8601 timestamp.']);
			}
		}

		try {
			$scheduled = $this->scheduled->update(
				$userId,
				$id,
				new CreatePost(
					authorId: $userId,
					content: $content,
					visibility: $visibility,
					contentWarning: $contentWarning,
					attachmentIds: $attachmentIds,
					recipientIds: $recipientIds,
					inReplyToId: $inReplyToId,
					language: $language,
				),
				$poll,
				$scheduledAt,
			);
		} catch (ScheduledPostNotFoundException) {
			throw HttpException::notFound('Scheduled post not found.');
		} catch (ScheduledPostConflictException) {
			throw new HttpException(409, 'conflict', 'The scheduled post can no longer be changed.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['scheduled_post' => $scheduled->toArray()]);
	}

	private function nullableString(array $body, string $field, ?string $fallback): ?string
	{
		if (!array_key_exists($field, $body)) {
			return $fallback;
		}

		$value = $body[$field];
		if ($value !== null && !is_string($value)) {
			throw HttpException::validationFailed([$field => $field . ' must be a string or null.']);
		}

		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value, string $field): array
	{
		if (!is_array($value) || !array_is_list($value)) {
			throw HttpException::validationFailed([$field => $field . ' must be a list of strings.']);
		}

		$result = [];
		foreach ($value as $entry) {
			if (!is_string($entry) || trim($entry) === '') {
				throw HttpException::validationFailed([$field => $field . ' must contain non-empty strings.']);
			}

			$result[] = $entry;
		}

		return $result;
	}

	private function pollFromInput(mixed $value): ?ScheduledPoll
	{
		if ($value === null) {
			return null;
		}

		if (!is_array($value)) {
			throw HttpException::validationFailed(['poll' => 'poll must be an object or null.']);
		}

		try {
			return ScheduledPoll::fromInput($value);
		} catch (InvalidArgumentException $exception) {
			throw HttpException::validationFailed(['poll' => $exception->getMessage()]);
		}
	}
}
