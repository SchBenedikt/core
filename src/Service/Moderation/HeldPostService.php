<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Moderation;

use AlohaSocial\Core\Domain\Moderation\HeldPost;
use AlohaSocial\Core\Domain\Moderation\HeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\HeldPostStatus;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Moderation\AdminAuthorization;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function is_array;
use function is_string;

/**
 * Moderator operations on posts a hold policy refused to publish.
 *
 * Approval calls the normal PostService write path with the hold check
 * disabled: exactly one Post, its links, its delivery jobs and its
 * notifications are created, and the held row stores the resulting post id.
 * A repeated approval returns that post instead of publishing twice.
 */
final class HeldPostService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly HeldPostRepository $heldPosts,
		private readonly PostService $posts,
		private readonly AdminAuthorization $admins,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function queue(string $moderatorId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		$this->requireModerator($moderatorId);

		return array_map(
			static fn (HeldPost $post): array => $post->toArray(),
			$this->heldPosts->queue($this->clamp($limit), $this->cursor($before)),
		);
	}

	/**
	 * Publishes a held post through the normal write path.
	 *
	 * @throws ModerationForbiddenException when the caller is not a moderator
	 * @throws ModerationNotFoundException  when the id is unknown
	 * @throws ModerationConflictException  when another moderator claimed it
	 * @throws ValidationException          when the stored draft is no longer valid
	 */
	public function approve(string $moderatorId, string $heldId, ?string $note = null): HeldPost
	{
		$this->requireModerator($moderatorId);
		$held = $this->heldPosts->find($heldId);
		if ($held === null) {
			throw new ModerationNotFoundException($heldId);
		}

		if ($held->status === HeldPostStatus::Approved && $held->postId !== null) {
			return $held;
		}

		if ($held->status === HeldPostStatus::Rejected) {
			throw new ModerationConflictException($heldId);
		}

		$at = Time::toSecondPrecision($this->clock->now());
		if (!$this->heldPosts->claim($heldId, $moderatorId, $at)) {
			$current = $this->heldPosts->find($heldId);
			if ($current?->status === HeldPostStatus::Approved && $current->postId !== null) {
				return $current;
			}

			throw new ModerationConflictException($heldId);
		}

		$command = $this->commandFromDraft($held);

		try {
			$post = $this->posts->create($command, applyHold: false);
		} catch (ValidationException $exception) {
			$this->heldPosts->release($heldId);

			throw $exception;
		} catch (\Throwable $exception) {
			$this->heldPosts->release($heldId);

			throw $exception;
		}

		$this->heldPosts->approve($heldId, $post->id, $moderatorId, $note, $at);

		$current = $this->heldPosts->find($heldId);
		if ($current === null) {
			throw new ModerationNotFoundException($heldId);
		}

		return $current;
	}

	/**
	 * Discards a held post without ever creating a Post row.
	 */
	public function reject(string $moderatorId, string $heldId, string $note): HeldPost
	{
		$this->requireModerator($moderatorId);
		$held = $this->heldPosts->find($heldId);
		if ($held === null) {
			throw new ModerationNotFoundException($heldId);
		}

		if ($held->status === HeldPostStatus::Rejected) {
			return $held;
		}

		if ($held->status === HeldPostStatus::Approved) {
			throw new ModerationConflictException($heldId);
		}

		$at = Time::toSecondPrecision($this->clock->now());
		if (!$this->heldPosts->claim($heldId, $moderatorId, $at)) {
			$current = $this->heldPosts->find($heldId);
			if ($current?->status === HeldPostStatus::Rejected) {
				return $current;
			}

			throw new ModerationConflictException($heldId);
		}

		$this->heldPosts->reject($heldId, $moderatorId, $note, $at);

		$current = $this->heldPosts->find($heldId);
		if ($current === null) {
			throw new ModerationNotFoundException($heldId);
		}

		return $current;
	}

	private function commandFromDraft(HeldPost $held): CreatePost
	{
		$draft = $held->draft;

		$content = $draft['content'] ?? null;
		$visibility = $draft['visibility'] ?? null;
		$contentWarning = $draft['content_warning'] ?? null;
		$attachmentIds = $draft['attachment_ids'] ?? [];
		$recipientIds = $draft['recipient_ids'] ?? [];
		$inReplyToId = $draft['in_reply_to_id'] ?? null;
		$quotedPostId = $draft['quoted_post_id'] ?? null;

		if (!is_string($content) || !is_string($visibility)) {
			throw new ValidationException(['content' => 'The held post has no usable content.']);
		}

		try {
			$visibilityValue = \AlohaSocial\Core\Domain\Post\Visibility::fromString($visibility);
		} catch (InvalidArgumentException $exception) {
			throw new ValidationException(['visibility' => $exception->getMessage()]);
		}

		return new CreatePost(
			authorId: $held->authorId,
			content: $content,
			visibility: $visibilityValue,
			contentWarning: is_string($contentWarning) ? $contentWarning : null,
			attachmentIds: is_array($attachmentIds) ? array_values(array_map('strval', $attachmentIds)) : [],
			recipientIds: is_array($recipientIds) ? array_values(array_map('strval', $recipientIds)) : [],
			inReplyToId: is_string($inReplyToId) && $inReplyToId !== '' ? $inReplyToId : null,
			quotedPostId: is_string($quotedPostId) && $quotedPostId !== '' ? $quotedPostId : null,
		);
	}

	private function requireModerator(string $userId): void
	{
		if (!$this->admins->isModerator($userId)) {
			throw new ModerationForbiddenException();
		}
	}

	private function clamp(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		return Time::parse(trim($before));
	}
}
