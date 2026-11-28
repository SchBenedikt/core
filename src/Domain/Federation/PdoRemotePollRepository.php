<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;
use Throwable;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class PdoRemotePollRepository implements RemotePollRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function upsert(RemotePoll $poll): void
	{
		$parameters = [
			'object_id' => $poll->objectId,
			'actor_id' => $poll->actorId,
			'activity_id' => $poll->activityId,
			'title' => $poll->title,
			'options' => $this->encodeOptions($poll),
			'multiple' => $poll->multiple ? 1 : 0,
			'ends_at' => $poll->endsAt === null ? null : Time::format($poll->endsAt),
			'closed_at' => $poll->closedAt === null ? null : Time::format($poll->closedAt),
			'voters_count' => $poll->votersCount,
			'updated_at' => Time::format($poll->updatedAt),
			'audience' => $poll->audience?->value,
		];

		try {
			$updated = $this->db->execute(
				'UPDATE ' . RemotePollSchema::TABLE . ' SET'
				. ' actor_id = :actor_id, activity_id = :activity_id, title = :title,'
				. ' options = :options, multiple = :multiple, ends_at = :ends_at,'
				. ' closed_at = COALESCE(closed_at, :closed_at),'
				. ' voters_count = :voters_count, updated_at = :updated_at,'
				. ' audience = :audience'
				. ' WHERE object_id = :object_id',
				$parameters,
			);

			if ($updated > 0) {
				return;
			}

			$this->db->execute(
				'INSERT INTO ' . RemotePollSchema::TABLE
				. ' (object_id, actor_id, activity_id, title, options, multiple, ends_at, closed_at,'
				. ' voters_count, updated_at, audience)'
				. ' VALUES (:object_id, :actor_id, :activity_id, :title, :options, :multiple, :ends_at,'
				. ' :closed_at, :voters_count, :updated_at, :audience)',
				$parameters,
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				// Another writer inserted the same question between the UPDATE
				// and the INSERT; applying the peer view again converges.
				$this->db->execute(
					'UPDATE ' . RemotePollSchema::TABLE . ' SET'
					. ' actor_id = :actor_id, activity_id = :activity_id, title = :title,'
					. ' options = :options, multiple = :multiple, ends_at = :ends_at,'
					. ' closed_at = COALESCE(closed_at, :closed_at),'
					. ' voters_count = :voters_count, updated_at = :updated_at,'
					. ' audience = :audience'
					. ' WHERE object_id = :object_id',
					$parameters,
				);

				return;
			}

			throw new DatabaseException('The remote poll could not be stored.', 0, $exception);
		}
	}

	public function find(string $objectId): ?RemotePoll
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . RemotePollSchema::TABLE . ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function markClosed(string $objectId, DateTimeImmutable $closedAt): bool
	{
		return $this->db->execute(
			'UPDATE ' . RemotePollSchema::TABLE
			. ' SET closed_at = :closed_at WHERE object_id = :object_id AND closed_at IS NULL',
			['object_id' => $objectId, 'closed_at' => Time::format($closedAt)],
		) > 0;
	}

	private function encodeOptions(RemotePoll $poll): string
	{
		return json_encode(
			array_map(static fn (RemotePollOption $option): array => $option->toArray(), $poll->options),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): RemotePoll
	{
		try {
			$decoded = json_decode((string) $row['options'], true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$decoded = [];
		}

		$options = [];
		if (is_array($decoded)) {
			foreach ($decoded as $option) {
				if (!is_array($option) || !is_string($option['title'] ?? null)) {
					continue;
				}

				try {
					$options[] = new RemotePollOption(
						$option['title'],
						is_int($option['votes_count'] ?? null) ? $option['votes_count'] : 0,
					);
				} catch (Throwable) {
					continue;
				}
			}
		}

		return new RemotePoll(
			objectId: (string) $row['object_id'],
			actorId: (string) $row['actor_id'],
			activityId: (string) $row['activity_id'],
			title: $row['title'] === null ? null : (string) $row['title'],
			options: $options,
			multiple: (int) $row['multiple'] === 1,
			endsAt: $row['ends_at'] === null ? null : Time::parse((string) $row['ends_at']),
			closedAt: $row['closed_at'] === null ? null : Time::parse((string) $row['closed_at']),
			votersCount: (int) $row['voters_count'],
			updatedAt: Time::parse((string) $row['updated_at']),
			audience: Visibility::tryFrom((string) ($row['audience'] ?? '')),
		);
	}
}
