<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function array_map;
use function count;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function trim;

/**
 * A poll specification stored on a scheduled post.
 *
 * The expiry is kept relative (`expires_in` seconds) or absolute
 * (`expires_at`) until publication: a poll scheduled days ahead must not
 * expire before its post exists. {@see resolve()} turns the spec into the
 * canonical {@see Poll} at publish time, so `expires_in` counts from the
 * moment the post goes out — the same semantics an immediate create has.
 */
final readonly class ScheduledPoll
{
	/**
	 * @param list<PollOption> $options
	 */
	public function __construct(
		public array $options,
		public bool $multiple,
		public bool $hideTotals,
		public ?int $expiresInSeconds = null,
		public ?DateTimeImmutable $expiresAt = null,
	) {
		if (!array_is_list($options)) {
			throw new InvalidArgumentException('Poll options must be a list.');
		}

		$count = count($options);
		if ($count < Poll::MIN_OPTIONS || $count > Poll::MAX_OPTIONS) {
			throw new InvalidArgumentException(
				'A poll needs between ' . Poll::MIN_OPTIONS . ' and ' . Poll::MAX_OPTIONS . ' options.',
			);
		}

		foreach ($options as $option) {
			if (!$option instanceof PollOption) {
				throw new InvalidArgumentException('A poll option must be a PollOption.');
			}
		}

		if (($this->expiresInSeconds === null) === ($this->expiresAt === null)) {
			throw new InvalidArgumentException('A scheduled poll needs exactly one expiry.');
		}

		if ($this->expiresInSeconds !== null
			&& ($this->expiresInSeconds < Poll::MIN_EXPIRY_SECONDS
				|| $this->expiresInSeconds > Poll::MAX_EXPIRY_SECONDS)
		) {
			throw new InvalidArgumentException(
				'A poll expires_in must be between ' . Poll::MIN_EXPIRY_SECONDS
					. ' and ' . Poll::MAX_EXPIRY_SECONDS . ' seconds.',
			);
		}
	}

	/**
	 * The canonical poll as it must exist once the post is published.
	 */
	public function resolve(DateTimeImmutable $publishAt): Poll
	{
		$expiresAt = $this->expiresInSeconds !== null
			? $publishAt->modify('+' . $this->expiresInSeconds . ' seconds')
			: $this->expiresAt;

		return new Poll(
			options: $this->options,
			multiple: $this->multiple,
			hideTotals: $this->hideTotals,
			expiresAt: Time::toSecondPrecision($expiresAt ?? $publishAt),
		);
	}

	/**
	 * The stored representation: option titles plus the unexpanded expiry.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'options' => array_map(
				static fn (PollOption $option): string => $option->title,
				$this->options,
			),
			'multiple' => $this->multiple,
			'hide_totals' => $this->hideTotals,
			'expires_in' => $this->expiresInSeconds,
			'expires_at' => $this->expiresAt === null ? null : Time::format($this->expiresAt),
		];
	}

	/**
	 * Parses the API shape — `options` as a list of titles, `multiple`,
	 * `hide_totals` and exactly one of `expires_in`/`expires_at` — into a
	 * spec. Anything malformed is an {@see InvalidArgumentException}; the
	 * caller maps it to a field error.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromInput(array $data): self
	{
		$rawOptions = $data['options'] ?? null;
		if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
			throw new InvalidArgumentException('poll.options must be a list of option titles.');
		}

		$options = [];
		foreach ($rawOptions as $raw) {
			if (!is_string($raw) || trim($raw) === '') {
				throw new InvalidArgumentException('poll.options must contain non-empty strings.');
			}

			$options[] = new PollOption($raw);
		}

		$multiple = $data['multiple'] ?? false;
		if (!is_bool($multiple)) {
			throw new InvalidArgumentException('poll.multiple must be a boolean.');
		}

		$hideTotals = $data['hide_totals'] ?? false;
		if (!is_bool($hideTotals)) {
			throw new InvalidArgumentException('poll.hide_totals must be a boolean.');
		}

		$expiresIn = $data['expires_in'] ?? null;
		if ($expiresIn !== null && !is_int($expiresIn)) {
			throw new InvalidArgumentException('poll.expires_in must be an integer number of seconds.');
		}

		$expiresAt = null;
		$rawExpiresAt = $data['expires_at'] ?? null;
		if ($rawExpiresAt !== null) {
			if (!is_string($rawExpiresAt)) {
				throw new InvalidArgumentException('poll.expires_at must be an ISO 8601 timestamp.');
			}

			try {
				$expiresAt = Time::parseIso8601($rawExpiresAt);
			} catch (\Throwable) {
				throw new InvalidArgumentException('poll.expires_at must be an ISO 8601 timestamp.');
			}
		}

		return new self(
			options: $options,
			multiple: $multiple,
			hideTotals: $hideTotals,
			expiresInSeconds: $expiresIn,
			expiresAt: $expiresAt,
		);
	}

	/**
	 * Rebuilds a spec from its stored array. Returns null for anything that
	 * does not look like one so a corrupt row cannot break hydration.
	 *
	 * @param array<string, mixed>|null $data
	 */
	public static function fromArray(?array $data): ?self
	{
		if ($data === null) {
			return null;
		}

		$rawOptions = $data['options'] ?? null;
		if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
			return null;
		}

		$options = [];
		foreach ($rawOptions as $raw) {
			if (!is_string($raw)) {
				return null;
			}

			try {
				$options[] = new PollOption($raw);
			} catch (InvalidArgumentException) {
				return null;
			}
		}

		$expiresIn = $data['expires_in'] ?? null;
		$expiresAt = $data['expires_at'] ?? null;
		$parsedAt = null;
		if (is_string($expiresAt) && $expiresAt !== '') {
			try {
				$parsedAt = Time::parse($expiresAt);
			} catch (\Throwable) {
				return null;
			}
		} elseif ($expiresAt !== null) {
			return null;
		}

		try {
			return new self(
				options: $options,
				multiple: is_bool($data['multiple'] ?? null) ? $data['multiple'] : false,
				hideTotals: is_bool($data['hide_totals'] ?? null) ? $data['hide_totals'] : false,
				expiresInSeconds: is_int($expiresIn) ? $expiresIn : null,
				expiresAt: $parsedAt,
			);
		} catch (InvalidArgumentException) {
			return null;
		}
	}
}
