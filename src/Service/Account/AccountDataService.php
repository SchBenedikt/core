<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Account;

use AlohaSocial\Core\Domain\Account\AccountDataRepository;
use AlohaSocial\Core\Domain\Account\AccountFederationCleanup;
use AlohaSocial\Core\Domain\Account\AccountDeletion;
use AlohaSocial\Core\Domain\Account\AccountDeletionSweeper;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use Throwable;

use function array_key_exists;
use function array_unique;
use function array_values;
use function base64_encode;
use function ksort;
use function sort;
use function trim;

/**
 * Complete export and erasure of one local account's core-owned data.
 *
 * The archive is deterministic: tables and rows have a stable order, the
 * encoded JSON contains no wall-clock timestamp, and the same stored data
 * therefore produces the same bytes. Blob contents are included as base64;
 * a metadata row whose object is missing is reported in `missing_blobs`
 * instead of silently disappearing.
 *
 * Private PDS key material is deliberately excluded from the archive. The
 * key rows keep their public metadata, but the sealed private seed is only
 * useful to the server and is not a meaningful user export. Erasure removes
 * the key rows and every PDS object.
 *
 * Host-owned account records, sessions and credentials are outside this core
 * (ADR 0006); the host must delete those after a successful core erasure.
 */
final class AccountDataService implements AccountDeletionSweeper
{
	public const FORMAT = 'alohasocial-account-archive';

	public const VERSION = 1;

	/**
	 * Tables that have no reliable owner column. They are named in the archive
	 * so an operator can see exactly what is not attributable instead of
	 * discovering a silently empty section later.
	 */
	private const NOT_ATTRIBUTABLE = [
		'social_activity_log' => 'Replay rows store an activity id, not the local user or actor that produced it.',
		'social_atproto_remote_account' => 'Inbound AT Protocol account cache shared by every reader.',
		'social_atproto_remote_record' => 'Inbound AT Protocol record cache shared by every reader.',
		'social_atproto_sync_checkpoint' => 'Inbound sync cursor for a source, not an account row.',
		'social_job' => 'Queue rows without the account subject envelope, including legacy payload-only jobs.',
		'social_moderation_action' => 'Moderator decisions are instance moderation records, not account-owned rows.',
		'social_pds_sequence' => 'Instance-wide firehose cursor, intentionally global.',
		'social_remote_pending_update' => 'Inbound remote-update cache keyed by remote object.',
		'social_remote_poll' => 'Cached remote question; only local votes are account-owned.',
		'social_remote_post' => 'Cached remote post shared by every reader.',
		'social_remote_thread_post' => 'Cached remote thread shared by every reader.',
		'social_search_trend' => 'Instance-wide trend aggregate, not account-owned.',
	];

	public function __construct(
		private readonly AccountDataRepository $repository,
		private readonly BlobStore $blobs,
		private readonly ActivityFactory $activities,
		private readonly ?JobQueue $queue = null,
		private readonly Clock $clock = new SystemClock(),
	) {
	}

	public function deletionStatus(string $userId): AccountDeletion
	{
		$userId = trim($userId);
		if ($userId === '') {
			throw new \InvalidArgumentException('An account deletion status needs a local user id.');
		}

		return $this->repository->deletion($userId) ?? AccountDeletion::active($userId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function export(string $userId): array
	{
		$userId = trim($userId);
		if ($userId === '') {
			throw new \InvalidArgumentException('An account export needs a local user id.');
		}

		$actorId = $this->activities->actorId($userId);
		$snapshot = $this->repository->snapshot($userId, $actorId);
		$tables = $snapshot->tables;
		ksort($tables, SORT_STRING);

		$blobs = [];
		$missing = [];
		foreach (array_values(array_unique($snapshot->blobPaths)) as $path) {
			if (array_key_exists($path, $blobs)) {
				continue;
			}

			try {
				if (!$this->blobs->exists($path)) {
					$missing[] = $path;
					continue;
				}

				$blobs[$path] = base64_encode($this->blobs->read($path));
			} catch (Throwable) {
				// A broken storage backend is not the same as a missing object.
				// The export must not claim a complete archive it could not read.
				throw new AccountDataUnavailableException(
					'The account archive could not read a blob at ' . $path . '.',
				);
			}
		}
		ksort($blobs, SORT_STRING);
		sort($missing, SORT_STRING);

		return [
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'account' => $userId,
			'actor' => $actorId,
			'deletion' => $this->deletionStatus($userId)->toArray(),
			'data' => $tables,
			'blobs' => $blobs,
			'missing_blobs' => $missing,
			'not_attributable' => self::NOT_ATTRIBUTABLE,
		];
	}

	public function erase(string $userId): AccountErasureReport
	{
		$userId = trim($userId);
		if ($userId === '') {
			throw new \InvalidArgumentException('An account erasure needs a local user id.');
		}

		$now = $this->clock->now();
		$deletion = $this->repository->beginDeletion($userId, $now);
		if ($deletion->erased()) {
			// Rows are already gone, but object-store failures can be retried
			// without reopening the account or repeating federation cleanup.
			return $this->reportFor($this->retryFailedBlobs($userId, $deletion));
		}

		if (!$this->repository->claimDeletion($userId, $now)) {
			return $this->reportFor($this->repository->deletion($userId) ?? $deletion);
		}

		try {
			// The state is already frozen before the first row is removed.
			// Removing queued work closes the other way an old request could
			// still publish after the account was deleted.
			$this->queue?->cancelBySubject(JobSubject::account($userId));
			$this->scheduleFederationCleanup($userId, $this->repository->federationCleanup($userId));

			$actorId = $this->activities->actorId($userId);
			$erasure = $this->repository->erase($userId, $actorId, $now);

			$deleted = 0;
			$failures = [];
			foreach (array_values(array_unique($erasure->blobPaths)) as $path) {
				try {
					if (!$this->blobs->exists($path)) {
						continue;
					}

					$this->blobs->delete($path);
					$deleted++;
				} catch (Throwable) {
					$failures[] = $path;
				}
			}

			$finished = $this->repository->finishDeletion(
				$userId,
				$erasure->counts,
				$deleted,
				$failures,
				$this->clock->now(),
			);
			$this->scheduleBlobRetry($userId, $finished);

			return $this->reportFor($finished);
		} catch (Throwable $exception) {
			// A retry must not wait for the lease when this process can release
			// its claim immediately.
			$this->repository->releaseDeletion($userId, $this->clock->now());

			throw $exception;
		}
	}

	private function scheduleFederationCleanup(string $userId, AccountFederationCleanup $cleanup): void
	{
		if ($this->queue === null) {
			return;
		}

		$keyId = $this->activities->keyId($userId);
		$delete = $this->activities->deleteActor($userId);
		foreach ($cleanup->followerInboxes as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $delete,
				'key_id' => $keyId,
			]);
		}

		foreach ($cleanup->following as $followed) {
			if ($followed->state === FollowState::Rejected) {
				continue;
			}

			$undo = $this->activities->undoFollow($userId, $followed);
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $followed->inboxUrl,
				'activity' => $undo,
				'key_id' => $keyId,
			]);
		}
	}

	/**
	 * Removes rows again after an in-flight handler committed past the freeze.
	 * For an already erased account, this also retries its persisted failed blob
	 * deletions without repeating federation cleanup.
	 */
	public function sweep(string $userId): void
	{
		$userId = trim($userId);
		if ($userId === '') {
			return;
		}

		$this->queue?->cancelBySubject(JobSubject::account($userId));
		$deletion = $this->repository->deletion($userId);
		if ($deletion?->erased()) {
			$this->retryFailedBlobs($userId, $deletion);
			return;
		}

		$actorId = $this->activities->actorId($userId);
		$erasure = $this->repository->erase($userId, $actorId, $this->clock->now());
		foreach (array_values(array_unique($erasure->blobPaths)) as $path) {
			try {
				if ($this->blobs->exists($path)) {
					$this->blobs->delete($path);
				}
			} catch (Throwable) {
				// The durable report already names blob failures from the main
				// pass; a second failure must not hide the write cleanup.
			}
		}
	}

	private function retryFailedBlobs(string $userId, AccountDeletion $deletion): AccountDeletion
	{
		// A competing repeat may update the list while these deletes run.
		// Re-read once after a lost compare-and-swap; deletes are idempotent.
		for ($attempt = 0; $attempt < 2 && $deletion->blobFailures !== []; ++$attempt) {
			$remaining = [];
			$deleted = 0;
			foreach ($deletion->blobFailures as $path) {
				try {
					if ($this->blobs->exists($path)) {
						$this->blobs->delete($path);
						++$deleted;
					}
				} catch (Throwable) {
					$remaining[] = $path;
				}
			}
			if ($remaining === $deletion->blobFailures) {
				return $deletion;
			}

			$updated = $this->repository->updateDeletionBlobFailures(
				$userId,
				$deletion->blobFailures,
				$remaining,
				$deleted,
				$this->clock->now(),
			);
			if ($updated !== null) {
				return $updated;
			}

			$deletion = $this->repository->deletion($userId) ?? $deletion;
		}

		return $deletion;
	}

	private function scheduleBlobRetry(string $userId, AccountDeletion $deletion): void
	{
		if ($deletion->blobFailures === [] || $this->queue === null) {
			return;
		}

		// This job deliberately has no account subject: it must run after the
		// tombstone blocks ordinary account work so the worker can retry storage.
		$this->queue->push(
			JobType::ACCOUNT_ERASURE_BLOB_RETRY,
			['user_id' => $userId],
			$this->clock->now()->modify('+1 minute'),
		);
	}

	private function reportFor(AccountDeletion $deletion): AccountErasureReport
	{
		return new AccountErasureReport(
			$deletion->counts,
			$deletion->blobsDeleted,
			$deletion->blobFailures,
			$deletion->state,
			$deletion->requestedAt,
			$deletion->completedAt,
		);
	}
}
