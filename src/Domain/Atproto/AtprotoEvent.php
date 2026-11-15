<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function strlen;
use function trim;

/**
 * One normalized event from an AT Protocol source.
 *
 * Relay commits, Jetstream events and AppView polling all become this shape
 * before the importer sees them. That keeps transport details in the host and
 * makes ordering, deduplication and account revocation testable without a
 * network. Record events are keyed by their validated AT URI; account events
 * never carry repository fields.
 */
final readonly class AtprotoEvent
{
	public const MAX_SOURCE_LENGTH = 64;

	public const MAX_CID_LENGTH = 256;

	public const MAX_REV_LENGTH = 64;

	/**
	 * @param array<string, mixed> $record
	 * @param list<AtprotoLabel>   $labels
	 */
	public function __construct(
		public string $source,
		public AtprotoEventKind $kind,
		public string $did,
		public DateTimeImmutable $receivedAt,
		public ?AtprotoRecordOperation $operation = null,
		public ?string $collection = null,
		public ?string $rkey = null,
		public ?string $cid = null,
		public ?string $rev = null,
		public ?int $sequence = null,
		public array $record = [],
		public ?bool $active = null,
		public ?AtprotoAccountStatus $accountStatus = null,
		public ?string $handle = null,
		public array $labels = [],
	) {
		$source = trim($source);
		if ($source === ''
			|| mb_strlen($source) > self::MAX_SOURCE_LENGTH
			|| preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $source) !== 1
		) {
			throw new InvalidArgumentException('An AT Protocol event needs a stable source name.');
		}

		if (!AtprotoIdentifiers::isDid($did)) {
			throw new InvalidArgumentException('An AT Protocol event needs a valid DID.');
		}

		if ($sequence !== null && $sequence < 0) {
			throw new InvalidArgumentException('An AT Protocol event sequence cannot be negative.');
		}

		if ($rev !== null && ($rev === '' || trim($rev) !== $rev || mb_strlen($rev) > self::MAX_REV_LENGTH)) {
			throw new InvalidArgumentException('An AT Protocol event revision is invalid.');
		}

		if ($cid !== null && ($cid === '' || trim($cid) !== $cid || mb_strlen($cid) > self::MAX_CID_LENGTH)) {
			throw new InvalidArgumentException('An AT Protocol event CID is invalid.');
		}

		if (!array_is_list($labels)) {
			throw new InvalidArgumentException('AT Protocol event labels must be a list.');
		}

		foreach ($labels as $label) {
			if (!$label instanceof AtprotoLabel) {
				throw new InvalidArgumentException('AT Protocol event labels must be label values.');
			}
		}

		if ($this->kind === AtprotoEventKind::Record) {
			$this->assertRecordEvent();
		} else {
			$this->assertAccountEvent();
		}
	}

	/**
	 * The validated record URI, or `null` for an account event.
	 */
	public function uri(): ?string
	{
		if ($this->kind !== AtprotoEventKind::Record) {
			return null;
		}

		return AtprotoIdentifiers::uri((string) $this->did, (string) $this->collection, (string) $this->rkey);
	}

	public function accountIsAvailable(): bool
	{
		return $this->kind === AtprotoEventKind::Account
			&& $this->active === true
			&& ($this->accountStatus === null || $this->accountStatus->isAvailable());
	}

	private function assertRecordEvent(): void
	{
		if ($this->operation === null
			|| !AtprotoIdentifiers::isCollection($this->collection)
			|| !AtprotoIdentifiers::isRkey($this->rkey)
		) {
			throw new InvalidArgumentException('A record event needs an operation, collection and record key.');
		}

		if ($this->active !== null || $this->accountStatus !== null || $this->handle !== null) {
			throw new InvalidArgumentException('A record event cannot carry account fields.');
		}

		$uri = AtprotoIdentifiers::uri($this->did, (string) $this->collection, (string) $this->rkey);
		if (strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol record URI is too long.');
		}

		if ($this->operation === AtprotoRecordOperation::Delete) {
			if ($this->record !== []) {
				throw new InvalidArgumentException('A delete event cannot carry a record body.');
			}

			return;
		}

		if (!is_array($this->record) || $this->record === []) {
			throw new InvalidArgumentException('A create or update event needs a record body.');
		}

		if (!is_string($this->cid) || $this->cid === '') {
			throw new InvalidArgumentException('A create or update event needs a CID.');
		}
	}

	private function assertAccountEvent(): void
	{
		if (!is_bool($this->active)) {
			throw new InvalidArgumentException('An account event needs an active flag.');
		}

		if ($this->operation !== null
			|| $this->collection !== null
			|| $this->rkey !== null
			|| $this->cid !== null
			|| $this->record !== []
			|| $this->labels !== []
		) {
			throw new InvalidArgumentException('An account event cannot carry record fields or labels.');
		}

		if ($this->handle !== null && !AtprotoIdentifiers::isHandle($this->handle)) {
			throw new InvalidArgumentException('An account event handle is invalid.');
		}

		if ($this->active && $this->accountStatus !== null && !$this->accountStatus->isAvailable()) {
			throw new InvalidArgumentException('An active account cannot carry an unavailable status.');
		}

		if (!$this->active && $this->accountStatus === AtprotoAccountStatus::Active) {
			throw new InvalidArgumentException('An inactive account cannot be marked active.');
		}
	}
}
