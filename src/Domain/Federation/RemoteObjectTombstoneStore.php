<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Durable record of remote objects an author deleted before we ever stored.
 *
 * ActivityPub delivery is unordered: a Delete may legitimately arrive before
 * the Create it cancels, and an outbox backfill or thread fetch may surface
 * the object long afterwards. Without a tombstone the late Create looks like
 * new content and resurrects a post the author already removed.
 *
 * A tombstone is owner-scoped: it is keyed by the verified signing actor and
 * the identity the Delete named, so one actor cannot suppress an object
 * authored by somebody else. The identity column holds either the object URL
 * or the activity URL the Delete pointed at; callers check both the object id
 * and the activity id of anything they are about to store.
 *
 * Retention is bounded twice, like the early-update buffer: an entry stops
 * applying after {@see self::TTL_SECONDS} and the store never keeps more than
 * {@see self::MAX_ENTRIES} rows, evicting the oldest first. The TTL is chosen
 * to comfortably outlive the replay log's claim window, so a peer retrying
 * inside that window is still stopped by the activity log while tombstones
 * cover the much longer backfill horizon. A host that needs a longer guarantee
 * raises the constant; there is no per-row extension.
 */
interface RemoteObjectTombstoneStore
{
	/** How long a tombstone may block re-creation of the object. */
	public const TTL_SECONDS = 2_592_000;

	/** The hard row cap, oldest entries evicted first. */
	public const MAX_ENTRIES = 8_192;

	/**
	 * Records that `$actorId` deleted the object or activity `$objectId`
	 * named. Re-recording the same pair keeps the earliest deletion time.
	 */
	public function record(
		string $actorId,
		string $objectId,
		string $activityId,
		DateTimeImmutable $deletedAt,
	): void;

	/**
	 * Whether this actor's deletion of this identity still applies. Entries
	 * older than {@see self::TTL_SECONDS} are treated as absent.
	 */
	public function isDeleted(string $actorId, string $objectId, DateTimeImmutable $now): bool;
}
