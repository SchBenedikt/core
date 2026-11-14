<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;
use PDOException;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class PdoNotificationPolicyRepository implements NotificationPolicyRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function find(string $userId): ?NotificationPolicy
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT actions, new_account_days FROM ' . NotificationPolicySchema::TABLE
				. ' WHERE user_id = :user_id',
				['user_id' => $userId],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification policy could not be read.', 0, $exception);
		}

		if ($row === null) {
			return null;
		}

		try {
			$actions = json_decode((string) $row['actions'], true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$actions = [];
		}

		return NotificationPolicy::fromStored([
			'actions' => is_array($actions) ? $actions : [],
			'new_account_days' => (int) $row['new_account_days'],
		]);
	}

	public function save(string $userId, NotificationPolicy $policy, DateTimeImmutable $updatedAt): void
	{
		$params = [
			'user_id' => $userId,
			'actions' => json_encode($policy->toArray()['actions'], JSON_THROW_ON_ERROR),
			'new_account_days' => $policy->newAccountDays,
			'updated_at' => Time::format($updatedAt),
		];

		try {
			$changed = $this->db->execute(
				'UPDATE ' . NotificationPolicySchema::TABLE
				. ' SET actions = :actions, new_account_days = :new_account_days, updated_at = :updated_at'
				. ' WHERE user_id = :user_id',
				$params,
			);
			if ($changed === 0 && $this->find($userId) === null) {
				$this->db->execute(
					'INSERT INTO ' . NotificationPolicySchema::TABLE
					. ' (user_id, actions, new_account_days, updated_at)'
					. ' VALUES (:user_id, :actions, :new_account_days, :updated_at)',
					$params,
				);
			}
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification policy could not be stored.', 0, $exception);
		}
	}
}
