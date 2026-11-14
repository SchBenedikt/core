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

/**
 * One accepted ActivityPub Move and its bounded migration progress.
 *
 * The event id is the activity id, so replaying a Move finds the same row.
 * The two processed lists make a batch retry idempotent: an interrupted run
 * records the edges it finished and the next run skips them. They contain
 * local row ids, never remote credentials or host user ids.
 */
final readonly class AccountMove
{
	public const MAX_ID_LENGTH = 512;

	public const MAX_URL_LENGTH = 512;

	/**
	 * @param list<string> $processedOutgoingIds
	 * @param list<string> $processedFollowerIds
	 */
	public function __construct(
		public string $eventId,
		public string $originActorId,
		public string $targetActorId,
		public string $targetInboxUrl,
		public AccountMoveDirection $direction,
		public AccountMoveState $state,
		public array $processedOutgoingIds,
		public array $processedFollowerIds,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $updatedAt,
	) {
		if ($eventId === '' || mb_strlen($eventId) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('An account move needs a bounded event id.');
		}

		foreach ([$originActorId, $targetActorId, $targetInboxUrl] as $url) {
			if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
				throw new InvalidArgumentException('An account move needs bounded actor and inbox URLs.');
			}
		}
	}

	public function isCompleted(): bool
	{
		return $this->state === AccountMoveState::Completed;
	}

	public function withOutgoingProcessed(string $id, DateTimeImmutable $at): self
	{
		if (in_array($id, $this->processedOutgoingIds, true)) {
			return $this;
		}

		$ids = $this->processedOutgoingIds;
		$ids[] = $id;

		return $this->with(processedOutgoingIds: $ids, updatedAt: $at);
	}

	public function withFollowerProcessed(string $id, DateTimeImmutable $at): self
	{
		if (in_array($id, $this->processedFollowerIds, true)) {
			return $this;
		}

		$ids = $this->processedFollowerIds;
		$ids[] = $id;

		return $this->with(processedFollowerIds: $ids, updatedAt: $at);
	}

	public function completed(DateTimeImmutable $at): self
	{
		return $this->with(state: AccountMoveState::Completed, updatedAt: $at);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'event_id' => $this->eventId,
			'origin_actor' => $this->originActorId,
			'target_actor' => $this->targetActorId,
			'target_inbox' => $this->targetInboxUrl,
			'direction' => $this->direction->value,
			'state' => $this->state->value,
			'processed_outgoing_ids' => $this->processedOutgoingIds,
			'processed_follower_ids' => $this->processedFollowerIds,
			'created_at' => Time::format($this->createdAt),
			'updated_at' => Time::format($this->updatedAt),
		];
	}

	/**
	 * @param list<string>|null $processedOutgoingIds
	 * @param list<string>|null $processedFollowerIds
	 */
	private function with(
		?array $processedOutgoingIds = null,
		?array $processedFollowerIds = null,
		?AccountMoveState $state = null,
		?DateTimeImmutable $updatedAt = null,
	): self {
		return new self(
			$this->eventId,
			$this->originActorId,
			$this->targetActorId,
			$this->targetInboxUrl,
			$this->direction,
			$state ?? $this->state,
			$processedOutgoingIds ?? $this->processedOutgoingIds,
			$processedFollowerIds ?? $this->processedFollowerIds,
			$this->createdAt,
			$updatedAt ?? $this->updatedAt,
		);
	}
}
