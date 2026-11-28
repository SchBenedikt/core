<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A validated post write that waits for its publication time.
 *
 * The row is not a post: it has no public URL and no activity id. `postId` is
 * reserved when the schedule is created and becomes the canonical post id on
 * publication, so a worker that crashes after writing the post and before
 * marking the schedule cannot publish it twice — the second insert collides
 * with the primary key instead of creating a copy.
 *
 * The command fields are the same ones {@see \AlohaSocial\Core\Service\CreatePost}
 * accepts. A poll is stored as a {@see ScheduledPoll} spec, not a resolved
 * {@see \AlohaSocial\Core\Domain\Post\Poll}: its expiry stays relative until
 * publication. The two id lists are stored as JSON lists for the same
 * reason — a scheduled row is input, not a canonical relationship.
 */
final readonly class ScheduledPost
{
	public const MAX_CONTENT_LENGTH = 5000;

	public const MAX_CONTENT_WARNING_LENGTH = 200;

	public const MAX_ATTACHMENTS = 10;

	public const MAX_FAILURE_LENGTH = 500;

	public function __construct(
		public string $id,
		public string $authorId,
		public string $postId,
		public string $content,
		public Visibility $visibility,
		public DateTimeImmutable $scheduledAt,
		public DateTimeImmutable $createdAt,
		public ScheduledPostState $state = ScheduledPostState::Scheduled,
		public ?string $contentWarning = null,
		/** @var list<string> */
		public array $attachmentIds = [],
		/** @var list<string> */
		public array $recipientIds = [],
		public ?string $inReplyToId = null,
		public ?DateTimeImmutable $publishedAt = null,
		public ?string $failureReason = null,
		public ?DateTimeImmutable $leaseUntil = null,
		public ?string $language = null,
		public ?ScheduledPoll $poll = null,
	) {
		if ($id === '' || $authorId === '' || $postId === '') {
			throw new InvalidArgumentException('A scheduled post needs an id, an author and a reserved post id.');
		}

		if (trim($content) === '') {
			throw new InvalidArgumentException('A scheduled post needs content.');
		}

		if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			throw new InvalidArgumentException('The scheduled content is too long.');
		}

		if ($contentWarning !== null && mb_strlen($contentWarning) > self::MAX_CONTENT_WARNING_LENGTH) {
			throw new InvalidArgumentException('The scheduled content warning is too long.');
		}

		if ($language !== null && (strlen($language) > 35
			|| preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1)
		) {
			throw new InvalidArgumentException('A scheduled post language must be a valid BCP 47 language tag.');
		}

		if (count($attachmentIds) > self::MAX_ATTACHMENTS) {
			throw new InvalidArgumentException('A scheduled post has too many attachments.');
		}

		foreach ([$attachmentIds, $recipientIds] as $list) {
			if (array_values($list) !== $list) {
				throw new InvalidArgumentException('Scheduled id lists must be lists.');
			}

			foreach ($list as $entry) {
				if (!is_string($entry) || $entry === '') {
					throw new InvalidArgumentException('A scheduled id must be a non-empty string.');
				}
			}
		}

		if ($inReplyToId !== null && trim($inReplyToId) === '') {
			throw new InvalidArgumentException('A reply target needs an id.');
		}

		if ($visibility !== Visibility::Direct && $recipientIds !== []) {
			throw new InvalidArgumentException('Only a direct scheduled post carries recipients.');
		}

		if ($failureReason !== null && mb_strlen($failureReason) > self::MAX_FAILURE_LENGTH) {
			throw new InvalidArgumentException('The failure reason is too long.');
		}
	}

	public function isPending(): bool
	{
		return $this->state->isPending();
	}

	public function isDue(DateTimeImmutable $now): bool
	{
		return $this->isPending() && $this->scheduledAt <= $now;
	}

	/**
	 * The same row with a new lifecycle value.
	 */
	public function withState(
		ScheduledPostState $state,
		?DateTimeImmutable $publishedAt = null,
		?string $failureReason = null,
		?DateTimeImmutable $leaseUntil = null,
	): self {
		return new self(
			id: $this->id,
			authorId: $this->authorId,
			postId: $this->postId,
			content: $this->content,
			visibility: $this->visibility,
			scheduledAt: $this->scheduledAt,
			createdAt: $this->createdAt,
			state: $state,
			contentWarning: $this->contentWarning,
			attachmentIds: $this->attachmentIds,
			recipientIds: $this->recipientIds,
			inReplyToId: $this->inReplyToId,
			publishedAt: $publishedAt,
			failureReason: $failureReason,
			leaseUntil: $leaseUntil,
			language: $this->language,
			poll: $this->poll,
		);
	}

	/**
	 * The shape the neutral API returns.
	 *
	 * `post_id` is intentionally absent: the reserved id must not become a
	 * way to probe whether an unpublished post exists.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'language' => $this->language,
			'visibility' => $this->visibility->value,
			'attachment_ids' => $this->attachmentIds,
			'recipient_ids' => $this->recipientIds,
			'in_reply_to_id' => $this->inReplyToId,
			'scheduled_at' => Time::format($this->scheduledAt),
			'created_at' => Time::format($this->createdAt),
			'state' => $this->state->toApiValue(),
			'published_at' => $this->publishedAt === null ? null : Time::format($this->publishedAt),
			'failure_reason' => $this->failureReason,
			'poll' => $this->poll?->toArray(),
		];
	}
}
