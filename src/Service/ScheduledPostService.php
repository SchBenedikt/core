<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPoll;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPost;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostConflictException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostIdempotencyConflictException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostState;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\Scheduling\ScheduledPublicationException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\TextLength;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

use function json_encode;
use function max;
use function min;
use function sort;
use function trim;

/**
 * Durable scheduled publishing.
 *
 * A schedule is validated and stored, then one job is queued for its time.
 * Publication goes through {@see PostService::create()} unchanged, so a
 * scheduled post gets exactly the federation, AT Protocol and notification
 * side effects of an immediate one — and none of them before its time.
 *
 * Idempotence has two layers: the reserved post id is the canonical post's
 * primary key, and the state transition is a compare-and-set. Together they
 * make a crash between the post write and the state update recoverable
 * without a duplicate post, and let a cancel win against a worker that has not
 * claimed the row yet.
 */
final class ScheduledPostService
{
	public const CONFIG_MINIMUM_LEAD = 'scheduling.minimum_lead_seconds';

	public const CONFIG_DAILY_LIMIT = 'scheduling.daily_limit';

	public const DEFAULT_MINIMUM_LEAD_SECONDS = 300;

	public const DEFAULT_DAILY_LIMIT = 30;

	public const MAX_LEAD_SECONDS = 31_536_000;

	public const LEASE_SECONDS = 300;

	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly ScheduledPostRepository $scheduled,
		private readonly PostService $posts,
		private readonly PostRepository $postReads,
		private readonly AttachmentRepository $attachments,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly Config $config,
	) {
	}

	/**
	 * Stores a validated write and queues its publication job.
	 *
	 * @throws ValidationException when the time, the quota or the content is refused
	 * @throws DatabaseException   when the row cannot be stored
	 */
	public function schedule(
		string $authorId,
		CreatePost $command,
		DateTimeImmutable $scheduledAt,
		?string $id = null,
		?ScheduledPoll $poll = null,
	): ScheduledPost {
		$now = $this->now();
		$scheduledAt = Time::toSecondPrecision($scheduledAt);
		$this->validateTime($scheduledAt, $now);

		[$content, $warning, $language] = $this->validateWrite($authorId, $command, $poll, $scheduledAt);

		$this->enforceQuota($authorId, $now);

		$scheduled = new ScheduledPost(
			id: $id ?? $this->ids->generate(),
			authorId: $authorId,
			postId: $this->ids->generate(),
			content: $content,
			visibility: $command->visibility,
			scheduledAt: $scheduledAt,
			createdAt: $now,
			contentWarning: $warning,
			attachmentIds: $command->attachmentIds,
			recipientIds: $command->recipientIds,
			inReplyToId: $command->inReplyToId,
			language: $language,
			poll: $poll,
		);

		try {
			$this->scheduled->insert($scheduled);
		} catch (DatabaseException $exception) {
			// A caller-chosen id (client idempotency key) may already name a
			// row. An identical payload replays the stored schedule without a
			// second publish job; a different one is an explicit conflict.
			if ($id === null) {
				throw $exception;
			}

			$existing = $this->scheduled->find($id);
			if ($existing === null) {
				throw $exception;
			}
			if (!$this->sameScheduledPayload($existing, $scheduled)) {
				throw new ScheduledPostIdempotencyConflictException($id);
			}

			return $existing;
		}

		$this->queue->push(
			JobType::SCHEDULED_POST_PUBLISH,
			['scheduled_post_id' => $scheduled->id],
			$scheduledAt,
			JobSubject::account($scheduled->authorId),
		);

		return $scheduled;
	}

	/**
	 * True when a retried schedule carries the same payload. Both rows are
	 * already normalized by this method, so the comparison is exact; the
	 * publish job and post id are runtime details, not payload.
	 */
	private function sameScheduledPayload(ScheduledPost $existing, ScheduledPost $retry): bool
	{
		$existingRecipients = $existing->recipientIds;
		$retryRecipients = $retry->recipientIds;
		sort($existingRecipients);
		sort($retryRecipients);

		return $existing->authorId === $retry->authorId
			&& $existing->content === $retry->content
			&& $existing->visibility === $retry->visibility
			&& $existing->contentWarning === $retry->contentWarning
			&& $existing->language === $retry->language
			&& $existing->attachmentIds === $retry->attachmentIds
			&& $existingRecipients === $retryRecipients
			&& $existing->inReplyToId === $retry->inReplyToId
			&& $existing->scheduledAt == $retry->scheduledAt
			&& self::pollFingerprint($existing->poll) === self::pollFingerprint($retry->poll);
	}

	private static function pollFingerprint(?ScheduledPoll $poll): ?string
	{
		return $poll === null ? null : json_encode($poll->toArray());
	}

	/**
	 * Replaces the whole write payload of a pending schedule.
	 *
	 * The reserved post id, author and creation time stay: an edit changes
	 * what will be published, never which post it becomes. The same payload
	 * rules as {@see schedule()} apply, but the daily quota does not — the
	 * row was already counted when it was created. A `null` time keeps the
	 * stored one.
	 *
	 * @throws ScheduledPostNotFoundException
	 * @throws ScheduledPostConflictException when publication already started
	 * @throws ValidationException
	 */
	public function update(
		string $authorId,
		string $id,
		CreatePost $command,
		?ScheduledPoll $poll,
		?DateTimeImmutable $scheduledAt = null,
	): ScheduledPost {
		$existing = $this->findOwned($authorId, $id);
		if ($existing->state !== ScheduledPostState::Scheduled) {
			throw new ScheduledPostConflictException($id);
		}

		$now = $this->now();
		$scheduledAt = Time::toSecondPrecision($scheduledAt ?? $existing->scheduledAt);
		$this->validateTime($scheduledAt, $now);

		[$content, $warning, $language] = $this->validateWrite($authorId, $command, $poll, $scheduledAt);

		$updated = new ScheduledPost(
			id: $existing->id,
			authorId: $existing->authorId,
			postId: $existing->postId,
			content: $content,
			visibility: $command->visibility,
			scheduledAt: $scheduledAt,
			createdAt: $existing->createdAt,
			contentWarning: $warning,
			attachmentIds: $command->attachmentIds,
			recipientIds: $command->recipientIds,
			inReplyToId: $command->inReplyToId,
			language: $language,
			poll: $poll,
		);

		if (!$this->scheduled->update($updated)) {
			throw new ScheduledPostConflictException($id);
		}

		if ($scheduledAt != $existing->scheduledAt) {
			$this->queue->push(
				JobType::SCHEDULED_POST_PUBLISH,
				['scheduled_post_id' => $id],
				$scheduledAt,
				JobSubject::account($existing->authorId),
			);
		}

		return $this->scheduled->find($id) ?? $updated;
	}

	/**
	 * @throws ScheduledPostNotFoundException
	 * @throws ValidationException
	 * @throws ScheduledPostConflictException
	 */
	public function reschedule(string $authorId, string $id, DateTimeImmutable $scheduledAt): ScheduledPost
	{
		$scheduled = $this->findOwned($authorId, $id);
		$scheduledAt = Time::toSecondPrecision($scheduledAt);
		$this->validateTime($scheduledAt, $this->now());

		if ($scheduled->state !== ScheduledPostState::Scheduled
			|| !$this->scheduled->reschedule($id, $scheduledAt)
		) {
			throw new ScheduledPostConflictException($id);
		}

		$this->queue->push(
			JobType::SCHEDULED_POST_PUBLISH,
			['scheduled_post_id' => $id],
			$scheduledAt,
			JobSubject::account($scheduled->authorId),
		);

		return $this->scheduled->find($id) ?? $scheduled;
	}

	/**
	 * @throws ScheduledPostNotFoundException
	 * @throws ScheduledPostConflictException when publication already started
	 */
	public function cancel(string $authorId, string $id): ScheduledPost
	{
		$this->findOwned($authorId, $id);

		if (!$this->scheduled->cancel($id)) {
			throw new ScheduledPostConflictException($id);
		}

		return $this->scheduled->find($id) ?? throw new ScheduledPostNotFoundException($id);
	}

	/**
	 * @throws ScheduledPostNotFoundException
	 */
	public function findOwned(string $authorId, string $id): ScheduledPost
	{
		$scheduled = $this->scheduled->find($id);
		if ($scheduled === null || $scheduled->authorId !== $authorId) {
			throw new ScheduledPostNotFoundException($id);
		}

		return $scheduled;
	}

	/**
	 * One page of the caller's schedules, newest first.
	 *
	 * The cursor is a schedule id owned by the caller; a foreign id is a
	 * validation error rather than an empty page, so paging cannot be used to
	 * test whether somebody else's schedule exists.
	 *
	 * @throws ValidationException
	 *
	 * @return list<ScheduledPost>
	 */
	public function forAuthor(string $authorId, int $limit, ?string $before = null): array
	{
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$beforeAt = null;
		$beforeId = null;

		if ($before !== null && trim($before) !== '') {
			$cursor = $this->scheduled->find(trim($before));
			if ($cursor === null || $cursor->authorId !== $authorId) {
				throw new ValidationException(['before' => 'The schedule cursor does not exist for this account.']);
			}

			$beforeAt = $cursor->createdAt;
			$beforeId = $cursor->id;
		}

		return $this->scheduled->forAuthor($authorId, $limit, $beforeAt, $beforeId);
	}

	/**
	 * Publishes a due schedule, or reschedules the job when it ran early.
	 *
	 * @throws ScheduledPublicationException when the write can never succeed
	 * @throws Throwable                      for transient failures the queue may retry
	 */
	public function publishDue(string $id): void
	{
		$scheduled = $this->scheduled->find($id);
		if ($scheduled === null || !$scheduled->isPending()) {
			return;
		}

		$now = $this->now();
		if ($scheduled->scheduledAt > $now) {
			// A reschedule or a duplicate job can make the payload early.
			// Queueing the same row again for the new time is idempotent.
			$this->queue->push(
				JobType::SCHEDULED_POST_PUBLISH,
				['scheduled_post_id' => $id],
				$scheduled->scheduledAt,
				JobSubject::account($scheduled->authorId),
			);

			return;
		}

		if (!$this->scheduled->claim($id, $now, $now->modify('+' . self::LEASE_SECONDS . ' seconds'))) {
			return;
		}

		$claimed = $this->scheduled->find($id);
		if ($claimed === null) {
			return;
		}

		try {
			$this->posts->create(new CreatePost(
				authorId: $claimed->authorId,
				content: $claimed->content,
				visibility: $claimed->visibility,
				contentWarning: $claimed->contentWarning,
				attachmentIds: $claimed->attachmentIds,
				postId: $claimed->postId,
				recipientIds: $claimed->recipientIds,
				inReplyToId: $claimed->inReplyToId,
				language: $claimed->language,
				poll: $claimed->poll?->resolve($now),
			));
		} catch (DatabaseException $exception) {
			// The reserved id is the publication guard. A duplicate means a
			// previous attempt already wrote the post and only the state
			// update was lost; a missing row means the insert really failed.
			$existing = $this->postReads->find($claimed->postId);
			if ($existing !== null && $existing->authorId === $claimed->authorId) {
				$this->scheduled->markPublished($id, $now);

				return;
			}

			$this->scheduled->markFailed($id, 'The post could not be stored.', $now);

			throw new ScheduledPublicationException('The scheduled post could not be stored.', 'storage_failed', $exception);
		} catch (ValidationException $exception) {
			$this->scheduled->markFailed($id, $exception->getMessage(), $now);

			throw new ScheduledPublicationException($exception->getMessage(), 'validation_failed', $exception);
		} catch (ConfigurationException $exception) {
			$this->scheduled->markFailed($id, $exception->getMessage(), $now);

			throw new ScheduledPublicationException($exception->getMessage(), 'configuration_failed', $exception);
		} catch (Throwable $exception) {
			// Unknown failure: let the row become due again so the queue's
			// retry can claim it instead of waiting for the lease.
			$this->scheduled->release($id);

			throw $exception;
		}

		$this->scheduled->markPublished($id, $now);
	}

	/**
	 * Normalizes and validates a write payload. Returns the trimmed content,
	 * content warning and language the stored row must carry.
	 *
	 * @return array{0: string, 1: ?string, 2: ?string}
	 *
	 * @throws ValidationException
	 */
	private function validateWrite(
		string $authorId,
		CreatePost $command,
		?ScheduledPoll $poll,
		DateTimeImmutable $scheduledAt,
	): array {
		$content = trim($command->content);
		$warning = $command->contentWarning === null ? null : trim($command->contentWarning);
		if ($warning === '') {
			$warning = null;
		}
		$language = $command->language === null ? null : trim($command->language);
		if ($language === '') {
			$language = null;
		}

		$errors = [];
		if ($content === '') {
			$errors['content'] = 'A scheduled post needs content.';
		} elseif (TextLength::mastodonWeighted($content) > PostService::MAX_CONTENT_LENGTH) {
			$errors['content'] = 'The content is too long.';
		}

		if ($warning !== null && mb_strlen($warning) > PostService::MAX_CONTENT_WARNING_LENGTH) {
			$errors['content_warning'] = 'The content warning is too long.';
		}
		if ($language !== null && (strlen($language) > 35
			|| preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1)
		) {
			$errors['language'] = 'language must be a valid BCP 47 language tag.';
		}

		if (count($command->attachmentIds) > ScheduledPost::MAX_ATTACHMENTS) {
			$errors['attachment_ids'] = 'A post has too many attachments.';
		}

		if ($command->visibility === Visibility::Direct && $command->recipientIds === []) {
			$errors['recipient_ids'] = 'A direct post needs at least one recipient.';
		}

		if ($command->visibility !== Visibility::Direct && $command->recipientIds !== []) {
			$errors['recipient_ids'] = 'Recipients are only used by direct posts.';
		}

		if ($poll !== null && $poll->expiresAt !== null && $poll->expiresAt <= $scheduledAt) {
			$errors['poll'] = 'The poll expires before the scheduled publication time.';
		}

		$attachments = $command->attachmentIds === []
			? []
			: $this->attachments->findMany($command->attachmentIds);
		foreach ($command->attachmentIds as $attachmentId) {
			$attachment = $attachments[$attachmentId] ?? null;
			if ($attachment === null || $attachment->ownerId !== $authorId) {
				$errors['attachment_ids'] = 'Every attachment must exist and belong to this account.';
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		return [$content, $warning, $language];
	}

	/**
	 * @throws ValidationException
	 */
	private function validateTime(DateTimeImmutable $scheduledAt, DateTimeImmutable $now): void
	{
		$lead = $scheduledAt->getTimestamp() - $now->getTimestamp();

		if ($lead < $this->minimumLeadSeconds()) {
			throw new ValidationException([
				'scheduled_at' => 'A schedule must be at least ' . $this->minimumLeadSeconds() . ' seconds in the future.',
			]);
		}

		if ($lead > self::MAX_LEAD_SECONDS) {
			throw new ValidationException(['scheduled_at' => 'A schedule may be at most one year ahead.']);
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function enforceQuota(string $authorId, DateTimeImmutable $now): void
	{
		$since = $now->modify('-1 day');
		if ($this->scheduled->countCreatedSince($authorId, $since) >= $this->dailyLimit()) {
			throw new ValidationException([
				'scheduled_at' => 'This account has reached its daily scheduling limit.',
			]);
		}
	}

	private function minimumLeadSeconds(): int
	{
		return max(
			0,
			min(
				self::MAX_LEAD_SECONDS,
				$this->config->getInt('social', self::CONFIG_MINIMUM_LEAD, self::DEFAULT_MINIMUM_LEAD_SECONDS),
			),
		);
	}

	private function dailyLimit(): int
	{
		return max(
			1,
			min(
				1000,
				$this->config->getInt('social', self::CONFIG_DAILY_LIMIT, self::DEFAULT_DAILY_LIMIT),
			),
		);
	}

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision($this->clock->now());
	}
}
