<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function array_map;
use function array_sum;
use function count;
use function mb_strlen;
use function trim;

/**
 * A Question received from a remote actor.
 *
 * Remote polls are stored separately from local `Poll` rows: the peer owns
 * the option titles and counts, and our optimistic votes are rows of their
 * own. `closedAt` is the peer's `closed` timestamp; `endsAt` is the schedule.
 * Either one closes the poll, evaluated against the clock on read, so an
 * expired poll needs no cleanup to stop accepting votes.
 */
final readonly class RemotePoll
{
	public const MIN_OPTIONS = 2;

	public const MAX_OPTIONS = 10;

	public ?string $title;

	public function __construct(
		public string $objectId,
		public string $actorId,
		public string $activityId,
		?string $title,
		/** @var list<RemotePollOption> */
		public array $options,
		public bool $multiple,
		public ?DateTimeImmutable $endsAt,
		public ?DateTimeImmutable $closedAt,
		public int $votersCount,
		public DateTimeImmutable $updatedAt,
		public ?Visibility $audience = null,
	) {
		if ($objectId === '' || $actorId === '' || $activityId === '') {
			throw new InvalidArgumentException('A remote poll needs an object, an actor and an activity.');
		}

		if (!array_is_list($options) || count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
			throw new InvalidArgumentException(
				'A remote poll needs between ' . self::MIN_OPTIONS . ' and ' . self::MAX_OPTIONS . ' options.',
			);
		}

		$seen = [];
		foreach ($options as $option) {
			if (!$option instanceof RemotePollOption) {
				throw new InvalidArgumentException('A remote poll option must be a RemotePollOption.');
			}

			if (isset($seen[$option->title])) {
				throw new InvalidArgumentException('A remote poll cannot repeat an option title.');
			}

			$seen[$option->title] = true;
		}

		if ($votersCount < 0) {
			throw new InvalidArgumentException('A remote poll voter count cannot be negative.');
		}

		$title = $title === null ? null : trim($title);
		if ($title !== null && ($title === '' || mb_strlen($title) > 255)) {
			throw new InvalidArgumentException('A remote poll title is invalid.');
		}

		$this->title = $title;
	}

	public function votesCount(): int
	{
		return array_sum(array_map(
			static fn (RemotePollOption $option): int => $option->votesCount,
			$this->options,
		));
	}

	/**
	 * @param DateTimeImmutable $now
	 */
	public function isClosed(DateTimeImmutable $now): bool
	{
		if ($this->closedAt !== null) {
			return true;
		}

		return $this->endsAt !== null && $this->endsAt <= Time::toSecondPrecision($now);
	}

	public function optionTitle(int $position): ?string
	{
		return $this->options[$position]->title ?? null;
	}

	/**
	 * The same question with a different audience claim. Used when a newer
	 * revision carries no addressing signal and the stored one must survive.
	 */
	public function withAudience(?Visibility $audience): self
	{
		return new self(
			objectId: $this->objectId,
			actorId: $this->actorId,
			activityId: $this->activityId,
			title: $this->title,
			options: $this->options,
			multiple: $this->multiple,
			endsAt: $this->endsAt,
			closedAt: $this->closedAt,
			votersCount: $this->votersCount,
			updatedAt: $this->updatedAt,
			audience: $audience,
		);
	}

	/**
	 * @return array{
	 *     object_id: string,
	 *     actor_id: string,
	 *     title: ?string,
	 *     options: list<array{title: string, votes_count: int}>,
	 *     multiple: bool,
	 *     ends_at: ?string,
	 *     closed_at: ?string,
	 *     votes_count: int,
	 *     voters_count: int,
	 *     updated_at: string,
	 *     audience: ?string
	 * }
	 */
	public function toArray(): array
	{
		return [
			'object_id' => $this->objectId,
			'actor_id' => $this->actorId,
			'title' => $this->title,
			'options' => array_map(
				static fn (RemotePollOption $option): array => $option->toArray(),
				$this->options,
			),
			'multiple' => $this->multiple,
			'ends_at' => $this->endsAt === null ? null : Time::format($this->endsAt),
			'closed_at' => $this->closedAt === null ? null : Time::format($this->closedAt),
			'votes_count' => $this->votesCount(),
			'voters_count' => $this->votersCount,
			'updated_at' => Time::format($this->updatedAt),
			'audience' => $this->audience?->value,
		];
	}
}
