<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;

use function array_is_list;

/**
 * A cached projection page for one remote AT Protocol author.
 *
 * The status is part of the page on purpose: a host can render cached posts
 * while showing that the relay/AppView is stale or unavailable. Reading this
 * page never performs a network request.
 */
final readonly class AtprotoFeedPage
{
	/**
	 * @param list<AtprotoPostProjection> $posts
	 */
	public function __construct(
		public array $posts,
		public ?string $nextCursor,
		public AtprotoSourceStatus $status,
		public ?DateTimeImmutable $lastEventAt,
	) {
		if (!array_is_list($posts)) {
			throw new \InvalidArgumentException('A feed page needs a list of posts.');
		}
	}
}
