<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPoll;
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

use function array_key_exists;
use function is_array;
use function is_string;
use function trim;

/**
 * `POST /api/v1/scheduled-posts`
 *
 * Accepts the same write fields as `POST /posts` plus a required
 * `scheduled_at`. Nothing is published here: the response is the stored
 * schedule, and its state is `scheduled` until a worker's job runs.
 */
final class CreateScheduledPostHandler implements Handler
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

		$content = $body['content'] ?? '';
		if (!is_string($content)) {
			throw HttpException::validationFailed(['content' => 'content must be a string.']);
		}

		$rawScheduledAt = $body['scheduled_at'] ?? null;
		if (!is_string($rawScheduledAt) || trim($rawScheduledAt) === '') {
			throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at is required.']);
		}

		$contentWarning = $body['content_warning'] ?? null;
		if ($contentWarning !== null && !is_string($contentWarning)) {
			throw HttpException::validationFailed(['content_warning' => 'content_warning must be a string or null.']);
		}

		$language = $body['language'] ?? null;
		if ($language !== null && !is_string($language)) {
			throw HttpException::validationFailed(['language' => 'language must be a string or null.']);
		}

		$attachmentIds = $this->stringList($body['attachment_ids'] ?? [], 'attachment_ids');
		$recipientIds = $this->stringList($body['recipient_ids'] ?? [], 'recipient_ids');

		$inReplyToId = $body['in_reply_to_id'] ?? null;
		if ($inReplyToId !== null && !is_string($inReplyToId)) {
			throw HttpException::validationFailed(['in_reply_to_id' => 'in_reply_to_id must be a string or null.']);
		}

		$rawVisibility = $body['visibility'] ?? Visibility::Public->value;
		if (!is_string($rawVisibility)) {
			throw HttpException::validationFailed(['visibility' => 'visibility must be a string.']);
		}

		$poll = null;
		if (array_key_exists('poll', $body) && $body['poll'] !== null) {
			if (!is_array($body['poll'])) {
				throw HttpException::validationFailed(['poll' => 'poll must be an object or null.']);
			}

			try {
				$poll = ScheduledPoll::fromInput($body['poll']);
			} catch (InvalidArgumentException $exception) {
				throw HttpException::validationFailed(['poll' => $exception->getMessage()]);
			}
		}

		try {
			$visibility = Visibility::fromString($rawVisibility);
		} catch (Throwable) {
			throw HttpException::validationFailed(['visibility' => 'visibility is not a supported value.']);
		}

					try {
			$scheduledAt = Time::parseIso8601($rawScheduledAt);
		} catch (Throwable) {
			throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at must be an ISO 8601 timestamp.']);
		}

		try {
			$scheduled = $this->scheduled->schedule(
				$userId,
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
				$scheduledAt,
				poll: $poll,
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['scheduled_post' => $scheduled->toArray()],
			201,
			['Location' => '/api/v1/scheduled-posts/' . rawurlencode($scheduled->id)],
		);
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
}
