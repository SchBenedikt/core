<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * The database result of an account erasure.
 *
 * The blob paths are returned separately because the bytes live behind the
 * host's BlobStore and are removed by the service after the database
 * transaction has committed.
 */
final readonly class AccountDataErasure
{
	/**
	 * @param array<string, int> $counts
	 * @param list<string> $blobPaths
	 */
	public function __construct(
		public array $counts,
		public array $blobPaths,
	) {
	}
}
