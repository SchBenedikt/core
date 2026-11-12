<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Read-side search over local posts.
 *
 * This is deliberately a separate port from {@see PostRepository}: a host may
 * have a post store without a search implementation. The Mastodon search
 * route then reports that it is not configured instead of silently returning
 * an empty page. The core's in-memory and PDO post repositories implement
 * both ports.
 */
interface PostSearchRepository
{
	/**
	 * Public and unlisted posts whose content contains the query.
	 *
	 * @param list<string>|null $authorIds null searches all local authors;
	 *                                     an empty list matches nothing
	 *
	 * @return list<Post> newest first
	 */
	public function search(
		string $query,
		int $limit,
		int $offset,
		?array $authorIds = null,
	): array;
}
