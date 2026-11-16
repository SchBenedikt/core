<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * A deterministic read of one account's stored rows.
 *
 * `tables` is keyed by table name and every row keeps the database column
 * names. `blobPaths` contains the attachment and PDS object paths whose bytes
 * the service has to read from the host's BlobStore.
 */
final readonly class AccountDataSnapshot
{
	/**
	 * @param array<string, list<array<string, mixed>>> $tables
	 * @param list<string> $blobPaths
	 */
	public function __construct(
		public array $tables,
		public array $blobPaths,
	) {
	}
}
