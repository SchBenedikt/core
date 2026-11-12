<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Optional search capability for local accounts.
 *
 * The core has no user table; a host may implement only
 * {@see LocalActorDirectory::has()} and no search. Mastodon account search
 * then answers that it is not configured rather than inventing users.
 */
interface LocalActorSearch
{
	/**
	 * @param list<string>|null $allowedIds null searches the whole directory;
	 *                                      an empty list matches nothing
	 *
	 * @return list<string> local user ids, stably ordered
	 */
	public function search(
		string $query,
		int $limit,
		int $offset,
		?array $allowedIds = null,
	): array;
}
