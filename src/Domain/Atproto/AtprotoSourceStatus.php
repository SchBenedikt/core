<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * How fresh a source's cached data is.
 *
 * The label is part of the read result so a UI never shows cached records as
 * if the relay or AppView had just answered. `unavailable` still permits a
 * cached read; it only marks the data as not current.
 */
final readonly class AtprotoSourceStatus
{
	public const LIVE = 'live';

	public const STALE = 'stale';

	public const UNAVAILABLE = 'unavailable';

	public const MAX_DETAIL_LENGTH = 500;

	public function __construct(
		public string $source,
		public string $label,
		public bool $stale,
		public ?DateTimeImmutable $checkedAt,
		public ?string $detail = null,
	) {
		if ($source === '' || mb_strlen($source) > AtprotoEvent::MAX_SOURCE_LENGTH) {
			throw new InvalidArgumentException('A source status needs a bounded source name.');
		}

		if (!in_array($label, [self::LIVE, self::STALE, self::UNAVAILABLE], true)) {
			throw new InvalidArgumentException('Unknown AT Protocol source status.');
		}

		if ($detail !== null && mb_strlen($detail) > self::MAX_DETAIL_LENGTH) {
			throw new InvalidArgumentException('A source status detail is too long.');
		}
	}

	public static function live(string $source, ?DateTimeImmutable $checkedAt = null): self
	{
		return new self($source, self::LIVE, false, $checkedAt);
	}

	public static function stale(string $source, ?DateTimeImmutable $checkedAt, ?string $detail = null): self
	{
		return new self($source, self::STALE, true, $checkedAt, self::cleanDetail($detail));
	}

	public static function unavailable(string $source, ?DateTimeImmutable $checkedAt, ?string $detail = null): self
	{
		return new self($source, self::UNAVAILABLE, true, $checkedAt, self::cleanDetail($detail));
	}

	public function isFresh(): bool
	{
		return $this->label === self::LIVE;
	}

	private static function cleanDetail(?string $detail): ?string
	{
		if ($detail === null) {
			return null;
		}

		$detail = trim($detail);
		if ($detail === '') {
			return null;
		}

		return mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);
	}
}
