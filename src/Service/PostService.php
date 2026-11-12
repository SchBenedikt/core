<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use Closure;

/**
 * Writing and removing posts.
 *
 * Everything that has to be true about a post — its length, its content
 * warning, its visibility, who may delete it — is decided here, not in a
 * controller. Delivery is a side effect: the post is stored first, then one
 * job per inbox is queued, so a slow peer never blocks the write.
 */
final class PostService
{
	public const MAX_CONTENT_LENGTH = 5000;

	public const MAX_CONTENT_WARNING_LENGTH = 200;

	public function __construct(
		private readonly PostRepository $posts,
		private readonly AttachmentRepository $attachments,
		private readonly JobQueue $queue,
		private readonly InboxResolver $inboxes,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?AtprotoPublishService $atproto = null,
	) {
	}

	public function create(CreatePost $command): Post
	{
		$content = trim($command->content);
		$warning = $command->contentWarning === null ? null : trim($command->contentWarning);
		if ($warning === '') {
			$warning = null;
		}

		$this->validate($command, $content, $warning);

		$post = new Post(
			id: $command->postId ?? $this->ids->generate(),
			authorId: $command->authorId,
			content: $content,
			visibility: $command->visibility,
			publishedAt: Time::toSecondPrecision($this->clock->now()),
			contentWarning: $warning,
			attachmentIds: $command->attachmentIds,
		);

		$this->posts->insert($post);
		$this->atproto?->schedule($post);
		$this->enqueueDelivery($post, fn (): array => $this->activities->createNote($post));

		return $post;
	}

	public function get(string $postId): Post
	{
		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted()) {
			throw new PostNotFoundException($postId);
		}

		return $post;
	}

	/**
	 * Reads a post as a particular viewer.
	 *
	 * A post that is not publicly visible must not be readable just because
	 * somebody guessed its id. The answer is `not found` rather than
	 * `forbidden`, so the existence of a non-public post does not leak either.
	 *
	 * @throws PostNotFoundException when the post is missing, deleted or not visible to the viewer
	 */
	public function getVisible(string $postId, ?string $viewerId): Post
	{
		$post = $this->get($postId);

		if ($post->visibility->isPubliclyVisible()) {
			return $post;
		}

		if ($viewerId !== null && $post->authorId === $viewerId) {
			return $post;
		}

		throw new PostNotFoundException($postId);
	}

	/**
	 * Deletes a post the actor owns.
	 *
	 * @throws PostNotFoundException      when the post does not exist
	 * @throws PostAccessDeniedException  when somebody else wrote it
	 */
	public function delete(string $actorId, string $postId): void
	{
		$post = $this->get($postId);

		if ($post->authorId !== $actorId) {
			throw new PostAccessDeniedException($postId, $actorId);
		}

		$deletedAt = Time::toSecondPrecision($this->clock->now());
		$this->posts->softDelete($postId, $deletedAt);
		$this->atproto?->scheduleDelete($post);

		$this->enqueueDelivery($post, fn (): array => $this->activities->deleteNote($post, $deletedAt));
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function validate(CreatePost $command, string $content, ?string $warning): void
	{
		$errors = [];

		if (trim($command->authorId) === '') {
			$errors['author'] = 'A post needs an author.';
		}

		if (mb_strlen($content) === 0) {
			$errors['content'] = 'A post needs text.';
		} elseif (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			$errors['content'] = 'A post may be at most ' . self::MAX_CONTENT_LENGTH . ' characters long.';
		}

		if ($warning !== null && mb_strlen($warning) > self::MAX_CONTENT_WARNING_LENGTH) {
			$errors['content_warning'] = 'A content warning may be at most '
				. self::MAX_CONTENT_WARNING_LENGTH . ' characters long.';
		}

		if (!array_is_list($command->attachmentIds)) {
			$errors['attachment_ids'] = 'Attachments must be a list of ids.';
		} elseif (count($command->attachmentIds) > Post::MAX_ATTACHMENTS) {
			$errors['attachment_ids'] = 'A post may have at most ' . Post::MAX_ATTACHMENTS . ' attachments.';
		} else {
			$seen = [];
			foreach ($command->attachmentIds as $attachmentId) {
				if (!is_string($attachmentId) || $attachmentId === '') {
					$errors['attachment_ids'] = 'An attachment id must be a non-empty string.';
					break;
				}

				if (isset($seen[$attachmentId])) {
					$errors['attachment_ids'] = 'An attachment cannot be used twice.';
					break;
				}

				$seen[$attachmentId] = true;
				$attachment = $this->attachments->find($attachmentId);
				if ($attachment === null || $attachment->ownerId !== $command->authorId) {
					$errors['attachment_ids'] = 'An attachment does not exist for this account.';
					break;
				}
			}
		}

		if ($command->visibility === Visibility::Direct) {
			// A direct post needs recipients, and the recipients of a post are
			// part of the follow graph this milestone does not have yet.
			// Refusing is honest; federating an empty audience is not.
			$errors['visibility'] = 'Direct posts are not available yet.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}
	}

	/**
	 * @param Closure(): array<string, mixed> $buildActivity
	 */
	private function enqueueDelivery(Post $post, Closure $buildActivity): void
	{
		$inboxes = array_values(array_unique(array_filter(
			$this->inboxes->inboxesFor($post->authorId),
			static fn (mixed $inbox): bool => is_string($inbox) && trim($inbox) !== '',
		)));

		if ($inboxes === []) {
			return;
		}

		$activity = $buildActivity();
		$keyId = $this->activities->keyId($post->authorId);

		foreach ($inboxes as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $activity,
				'key_id' => $keyId,
			]);
		}
	}
}
