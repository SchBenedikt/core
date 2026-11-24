<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * The durable position and last outcome of one AT Protocol source.
 *
 * The cursor belongs to the source adapter and is opaque to the core. The
 * sequence tracks the highest applied source event for ordering and status;
 * the adapter reports continuity gaps explicitly. `stale`
 * is persisted so a read after a failed poll can label cached content instead
 * of pretending it is fresh; it is not derived from the clock.
 */
final readonly class AtprotoSyncCheckpoint
{
	public const MAX_CURSOR_LENGTH = 2048;

	public const MAX_ERROR_LENGTH = 500;

	public function __construct(
		public string $source,
		public ?string $cursor,
		public ?int $lastSequence,
		public ?DateTimeImmutable $lastEventAt,
		public DateTimeImmutable $lastAttemptAt,
		public ?string $lastError,
		public bool $stale,
		public bool $reconcileRequired,
	) {
		if ($source === '' || mb_strlen($source) > AtprotoEvent::MAX_SOURCE_LENGTH) {
			throw new InvalidArgumentException('A sync checkpoint needs a bounded source name.');
		}

		if ($cursor !== null && ($cursor === '' || mb_strlen($cursor) > self::MAX_CURSOR_LENGTH)) {
			throw new InvalidArgumentException('A sync cursor is invalid.');
		}

		if ($lastSequence !== null && $lastSequence < 0) {
			throw new InvalidArgumentException('A sync sequence cannot be negative.');
		}

		if ($lastError !== null && ($lastError === '' || mb_strlen($lastError) > self::MAX_ERROR_LENGTH)) {
			throw new InvalidArgumentException('A sync error message is invalid.');
		}
	}

	public static function initial(string $source, DateTimeImmutable $at): self
	{
		return new self($source, null, null, null, $at, null, false, false);
	}

	public function failed(DateTimeImmutable $at, string $error): self
	{
		return new self(
			$this->source,
			$this->cursor,
			$this->lastSequence,
			$this->lastEventAt,
			$at,
			self::boundedError($error),
			true,
			$this->reconcileRequired,
		);
	}

	public function advanced(
		?string $cursor,
		?int $lastSequence,
		?DateTimeImmutable $lastEventAt,
		DateTimeImmutable $at,
		bool $reconcileRequired,
	): self {
		return new self(
			$this->source,
			$cursor,
			$lastSequence ?? $this->lastSequence,
			$lastEventAt ?? $this->lastEventAt,
			$at,
			null,
			false,
			$reconcileRequired,
		);
	}

	public function withReconcileRequired(bool $required, DateTimeImmutable $at): self
	{
		return new self(
			$this->source,
			$this->cursor,
			$this->lastSequence,
			$this->lastEventAt,
			$at,
			$this->lastError,
			$this->stale,
			$required,
		);
	}

	private static function boundedError(string $error): string
	{
		$error = trim($error);
		if ($error === '') {
			$error = 'The AT Protocol source failed.';
		}

		return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
	}
}
