<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function array_map;
use function count;

/**
 * A poll attached to one local post.
 *
 * The core stores the poll next to its post and has no voting endpoint yet;
 * the counts and the per-viewer fields are therefore zero/false. The expiry is
 * computed once at creation time and is not a scheduled job: `expired` is
 * derived from the clock when the status is presented.
 */
final readonly class Poll
{
	public const MIN_OPTIONS = 2;

	public const MAX_OPTIONS = 4;

	public const MIN_EXPIRY_SECONDS = 300;

	public const MAX_EXPIRY_SECONDS = 604800;

	public DateTimeImmutable $expiresAt;

	/**
	 * @param list<PollOption> $options
	 */
	public function __construct(
		public array $options,
		public bool $multiple,
		public bool $hideTotals,
		DateTimeImmutable $expiresAt,
		public int $votesCount = 0,
		public int $votersCount = 0,
	) {
		if (!array_is_list($options)) {
			throw new InvalidArgumentException('Poll options must be a list.');
		}

		$count = count($options);
		if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
			throw new InvalidArgumentException(
				'A poll needs between ' . self::MIN_OPTIONS . ' and ' . self::MAX_OPTIONS . ' options.',
			);
		}

		foreach ($options as $option) {
			if (!$option instanceof PollOption) {
				throw new InvalidArgumentException('A poll option must be a PollOption.');
			}
		}

		if ($votesCount < 0 || $votersCount < 0) {
			throw new InvalidArgumentException('Poll counts cannot be negative.');
		}

		if ($votersCount > $votesCount) {
			throw new InvalidArgumentException('A poll cannot have more voters than votes.');
		}

		$this->expiresAt = Time::toSecondPrecision($expiresAt);
	}

	public function isExpired(DateTimeImmutable $now): bool
	{
		return $this->expiresAt <= Time::toSecondPrecision($now);
	}

	/**
	 * @return array{
	 *     options: list<array{title: string, votes_count: int}>,
	 *     multiple: bool,
	 *     hide_totals: bool,
	 *     expires_at: string,
	 *     votes_count: int,
	 *     voters_count: int
	 * }
	 */
	public function toArray(): array
	{
		return [
			'options' => array_map(
				static fn (PollOption $option): array => $option->toArray(),
				$this->options,
			),
			'multiple' => $this->multiple,
			'hide_totals' => $this->hideTotals,
			'expires_at' => Time::format($this->expiresAt),
			'votes_count' => $this->votesCount,
			'voters_count' => $this->votersCount,
		];
	}
}
