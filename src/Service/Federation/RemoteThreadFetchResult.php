<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;

/**
 * One fetched object plus the direct replies embedded in or linked from it.
 */
final readonly class RemoteThreadFetchResult
{
	/**
	 * @param list<RemoteThreadPost> $replies
	 */
	public function __construct(
		public RemoteThreadPost $post,
		public array $replies = [],
		public ?string $repliesUrl = null,
	) {
	}
}
