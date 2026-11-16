<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Account;

use AlohaSocial\Core\Domain\Account\AccountDataRepository;
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
final class AccountDataService
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
		'social_job' => 'Queue rows carry no subject column; their JSON payload is not reliably attributable.',
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
	) {
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

		$actorId = $this->activities->actorId($userId);
		$erasure = $this->repository->erase($userId, $actorId);

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

		return new AccountErasureReport($erasure->counts, $deleted, $failures);
	}
}
