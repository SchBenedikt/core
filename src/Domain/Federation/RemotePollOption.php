<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * One choice of a remote Question, with the count the peer published.
 *
 * The count comes from the option's `replies.totalItems`. Local votes are not
 * folded into this value: keeping the peer's number separate is what makes a
 * later Question update replace the remote view without double-counting our
 * own optimistic vote.
 */
final readonly class RemotePollOption
{
	public const MAX_TITLE_LENGTH = 255;

	public function __construct(
		public string $title,
		public int $votesCount = 0,
	) {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidArgumentException('A poll option needs a title.');
		}

		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidArgumentException('A poll option title is too long.');
		}

		if ($votesCount < 0) {
			throw new InvalidArgumentException('A poll option count cannot be negative.');
		}
	}

	/**
	 * @return array{title: string, votes_count: int}
	 */
	public function toArray(): array
	{
		return ['title' => $this->title, 'votes_count' => $this->votesCount];
	}
}
