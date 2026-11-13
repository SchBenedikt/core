<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * One choice of a local poll.
 *
 * Vote counts are stored with the option and advanced atomically with a
 * poll vote. A freshly created poll starts at zero.
 */
final readonly class PollOption
{
	public const MAX_TITLE_LENGTH = 50;

	public string $title;

	public function __construct(
		string $title,
		public int $votesCount = 0,
	) {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidArgumentException('A poll option needs a title.');
		}

		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidArgumentException(
				'A poll option may be at most ' . self::MAX_TITLE_LENGTH . ' characters long.',
			);
		}

		if ($votesCount < 0) {
			throw new InvalidArgumentException('A poll option vote count cannot be negative.');
		}

		$this->title = $title;
	}

	/**
	 * @return array{title: string, votes_count: int}
	 */
	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'votes_count' => $this->votesCount,
		];
	}
}
