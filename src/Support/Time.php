<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Time handling shared by the domain, the database and the federation layer.
 *
 * Every persisted or federated timestamp has second precision and UTC, so a
 * value that made a round trip through the database equals the value that was
 * written. Stored strings use the fixed-width RFC 3339 form, which keeps
 * lexicographic order equal to chronological order on every supported
 * database — including SQLite, where there is no native timestamp type.
 */
final class Time
{
	public const STORAGE_FORMAT = 'Y-m-d\TH:i:s\Z';

	private function __construct()
	{
	}

	/**
	 * Drops sub-second precision and switches to UTC, keeping the instant.
	 */
	public static function toSecondPrecision(DateTimeImmutable $time): DateTimeImmutable
	{
		$utc = $time->setTimezone(new DateTimeZone('UTC'));

		return $utc->setTime(
			(int) $utc->format('H'),
			(int) $utc->format('i'),
			(int) $utc->format('s'),
		);
	}

	/**
	 * The canonical string form: `2026-11-11T18:14:00Z`.
	 */
	public static function format(DateTimeImmutable $time): string
	{
		return self::toSecondPrecision($time)->format(self::STORAGE_FORMAT);
	}

	/**
	 * @throws InvalidArgumentException when the value is not a canonical timestamp
	 */
	public static function parse(string $value): DateTimeImmutable
	{
		$parsed = DateTimeImmutable::createFromFormat(
			self::STORAGE_FORMAT,
			$value,
			new DateTimeZone('UTC'),
		);

		// createFromFormat is lenient about overflow (2026-02-31 rolls into
		// March), so the round trip is what proves the input was strict.
		if ($parsed === false || $parsed->format(self::STORAGE_FORMAT) !== $value) {
			throw new InvalidArgumentException('Not a canonical timestamp: ' . $value);
		}

		return $parsed;
	}

	/**
	 * The IMF-fixdate form HTTP signatures and `Date` headers use.
	 */
	public static function httpDate(DateTimeImmutable $time): string
	{
		return $time->setTimezone(new DateTimeZone('UTC'))
			->format('D, d M Y H:i:s \G\M\T');
	}
}
