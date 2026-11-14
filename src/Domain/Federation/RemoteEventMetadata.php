<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * Schedule and place of a remote Event.
 *
 * `participationUrl` stays a separate, validated link: it is how Mobilizon
 * points at its own participation flow, and it is never the object id. An end
 * before the start is refused rather than rendered as a negative duration.
 */
final readonly class RemoteEventMetadata
{
	public const MAX_NAME_LENGTH = 255;

	public const MAX_LOCATION_LENGTH = 255;

	public const MAX_URL_LENGTH = 2048;

	public function __construct(
		public string $name,
		public DateTimeImmutable $startsAt,
		public ?DateTimeImmutable $endsAt = null,
		public ?string $location = null,
		public ?string $participationUrl = null,
		public ?RemoteEventStatus $status = null,
	) {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('An event needs a name.');
		}

		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('The event name is too long.');
		}

		if ($location !== null && mb_strlen($location) > self::MAX_LOCATION_LENGTH) {
			throw new InvalidArgumentException('The event location is too long.');
		}

		if ($participationUrl !== null && mb_strlen($participationUrl) > self::MAX_URL_LENGTH) {
			throw new InvalidArgumentException('The participation URL is too long.');
		}

		if ($endsAt !== null && $endsAt < $startsAt) {
			throw new InvalidArgumentException('An event cannot end before it starts.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'starts_at' => Time::format($this->startsAt),
			'ends_at' => $this->endsAt === null ? null : Time::format($this->endsAt),
			'location' => $this->location,
			'participation_url' => $this->participationUrl,
			'status' => $this->status?->value,
		];
	}
}
