<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Service\MediaBlobDeletion;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Retention cleanup for orphan media, read notifications and thread caches.
 *
 * The service is deliberately a worker operation, not an HTTP route: a host
 * authenticates nobody here because nobody may delete another account's data
 * through a request. A host calls it from its cron/worker or enqueues the
 * `cleanup` job.
 */
final class CleanupService
{
	public function __construct(
		private readonly AttachmentRepository $attachments,
		private readonly NotificationRepository $notifications,
		private readonly RemoteThreadRepository $threads,
		private readonly ?BlobStore $blobs,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger = new NullLogger(),
		private readonly ?MediaBlobDeletion $blobDeletion = null,
	) {
	}

	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when a store cannot be read or written
	 */
	public function run(CleanupOptions $options): CleanupReport
	{
		$runAt = Time::toSecondPrecision($this->clock->now());
		$results = [];

		foreach ($this->kinds($options->kind) as $kind) {
			$results[] = $this->runKind($kind, $options, $runAt);
		}

		return new CleanupReport($runAt, $options->dryRun, $results);
	}

	private function runKind(string $kind, CleanupOptions $options, DateTimeImmutable $now): CleanupResult
	{
		return match ($kind) {
			CleanupOptions::KIND_MEDIA => $this->media(
				$options,
				$this->cutoff($now, $options->mediaRetentionDays),
			),
			CleanupOptions::KIND_NOTIFICATIONS => $this->notifications(
				$options,
				$this->cutoff($now, $options->notificationRetentionDays),
			),
			default => $this->threads(
				$options,
				$this->cutoff($now, $options->threadRetentionDays),
			),
		};
	}

	private function media(CleanupOptions $options, DateTimeImmutable $cutoff): CleanupResult
	{
		if ($this->blobs === null) {
			return new CleanupResult(
				kind: CleanupOptions::KIND_MEDIA,
				cutoff: $cutoff,
				matched: 0,
				deleted: 0,
				dryRun: $options->dryRun,
				skippedReason: 'cleanup_media_unavailable',
			);
		}

		$matched = $this->attachments->countUnattachedBefore($cutoff);
		if ($options->dryRun) {
			return new CleanupResult(
				kind: CleanupOptions::KIND_MEDIA,
				cutoff: $cutoff,
				matched: $matched,
				deleted: 0,
				dryRun: true,
			);
		}

		$deleted = 0;
		foreach ($this->attachments->unattachedBefore($cutoff, $options->limit) as $attachment) {
			if (!$this->attachments->deleteIfUnattached($attachment->id)) {
				continue;
			}

			$deleted++;
			$this->deleteBlob($attachment);
		}

		return new CleanupResult(
			kind: CleanupOptions::KIND_MEDIA,
			cutoff: $cutoff,
			matched: $matched,
			deleted: $deleted,
			dryRun: false,
		);
	}

	private function notifications(CleanupOptions $options, DateTimeImmutable $cutoff): CleanupResult
	{
		$matched = $this->notifications->countReadBefore($cutoff);
		if ($options->dryRun) {
			return new CleanupResult(
				kind: CleanupOptions::KIND_NOTIFICATIONS,
				cutoff: $cutoff,
				matched: $matched,
				deleted: 0,
				dryRun: true,
			);
		}

		return new CleanupResult(
			kind: CleanupOptions::KIND_NOTIFICATIONS,
			cutoff: $cutoff,
			matched: $matched,
			deleted: $this->notifications->deleteReadBefore($cutoff, $options->limit),
			dryRun: false,
		);
	}

	private function threads(CleanupOptions $options, DateTimeImmutable $cutoff): CleanupResult
	{
		$matched = $this->threads->countFetchedBefore($cutoff);
		if ($options->dryRun) {
			return new CleanupResult(
				kind: CleanupOptions::KIND_THREADS,
				cutoff: $cutoff,
				matched: $matched,
				deleted: 0,
				dryRun: true,
			);
		}

		return new CleanupResult(
			kind: CleanupOptions::KIND_THREADS,
			cutoff: $cutoff,
			matched: $matched,
			deleted: $this->threads->deleteFetchedBefore($cutoff, $options->limit),
			dryRun: false,
		);
	}

	private function deleteBlob(Attachment $attachment): void
	{
		foreach ([$attachment->storageKey, $attachment->thumbnailKey] as $path) {
			if ($this->blobDeletion !== null) {
				$this->blobDeletion->delete($path);
				continue;
			}

			try {
				$this->blobs?->delete($path);
			} catch (Throwable $exception) {
				// The metadata row is already gone. A missing object is not an
				// error; a transport/backend failure is queued for retry when the
				// host provides the shared media deletion service.
				$this->logger->warning('Cleanup could not delete an attachment blob.', [
					'attachment_id' => $attachment->id,
					'path' => $path,
					'reason' => 'cleanup_blob_delete_failed',
					'exception' => $exception,
				]);
			}
		}
	}

	private function cutoff(DateTimeImmutable $now, int $days): DateTimeImmutable
	{
		return Time::toSecondPrecision($now->modify('-' . $days . ' days'));
	}

	/**
	 * @return list<string>
	 */
	private function kinds(string $kind): array
	{
		return $kind === CleanupOptions::KIND_ALL
			? [CleanupOptions::KIND_MEDIA, CleanupOptions::KIND_NOTIFICATIONS, CleanupOptions::KIND_THREADS]
			: [$kind];
	}
}
