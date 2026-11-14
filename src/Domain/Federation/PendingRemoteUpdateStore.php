<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Holds updates that arrived before the post they belong to.
 *
 * The store is deliberately bounded twice: an entry expires after
 * {@see self::TTL_SECONDS}, and the table never keeps more than
 * {@see self::MAX_ENTRIES} rows. A peer cannot fill it without limit, and an
 * update whose Create never arrives simply disappears.
 */
interface PendingRemoteUpdateStore
{
	/** How long an early update may wait for its post. */
	public const TTL_SECONDS = 86_400;

	/** The hard row cap, oldest entries evicted first. */
	public const MAX_ENTRIES = 256;

	/**
	 * Stores the update, replacing an older entry for the same object, and
	 * evicts expired and over-cap entries.
	 */
	public function store(PendingRemoteUpdate $update): void;

	/**
	 * Removes and returns the stored update for this object when the actor
	 * owns it and the entry has not expired. Anything else is discarded.
	 */
	public function take(string $objectId, string $actorId, DateTimeImmutable $now): ?PendingRemoteUpdate;
}
