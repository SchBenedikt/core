<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

/**
 * One recipient's sender-category policy.
 *
 * A new recipient starts with the conservative {@see self::default()}: people
 * with a follow relationship are allowed, while new and unknown senders are
 * held for review. {@see self::legacy()} is all-allow and exists for a host
 * that migrates accounts that already had notifications before the policy
 * existed. A stored policy always wins over both defaults.
 */
final readonly class NotificationPolicy
{
	public const DEFAULT_NEW_ACCOUNT_DAYS = 7;

	public const MAX_NEW_ACCOUNT_DAYS = 3650;

	/**
	 * @param array<string, NotificationPolicyAction> $actions keyed by category value
	 */
	public function __construct(
		public array $actions,
		public int $newAccountDays = self::DEFAULT_NEW_ACCOUNT_DAYS,
	) {
		if ($newAccountDays < 0 || $newAccountDays > self::MAX_NEW_ACCOUNT_DAYS) {
			throw new InvalidArgumentException('new_account_days is out of range.');
		}

		foreach (NotificationSenderCategory::cases() as $category) {
			if (!array_key_exists($category->value, $actions)) {
				throw new InvalidArgumentException('Every sender category needs a policy action.');
			}

			if (!$actions[$category->value] instanceof NotificationPolicyAction) {
				throw new InvalidArgumentException('A policy action is not supported.');
			}
		}
	}

	public static function default(): self
	{
		return new self([
			NotificationSenderCategory::Mutual->value => NotificationPolicyAction::Allow,
			NotificationSenderCategory::Following->value => NotificationPolicyAction::Allow,
			NotificationSenderCategory::Follower->value => NotificationPolicyAction::Allow,
			NotificationSenderCategory::New->value => NotificationPolicyAction::Hold,
			NotificationSenderCategory::Stranger->value => NotificationPolicyAction::Hold,
		]);
	}

	public static function legacy(): self
	{
		$actions = [];
		foreach (NotificationSenderCategory::cases() as $category) {
			$actions[$category->value] = NotificationPolicyAction::Allow;
		}

		return new self($actions);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromStored(array $data): self
	{
		$actions = self::default()->actions;
		$raw = $data['actions'] ?? null;
		if (is_array($raw)) {
			foreach ($raw as $category => $action) {
				if (!is_string($category) || !is_string($action)) {
					continue;
				}

				$categoryValue = NotificationSenderCategory::tryFrom($category);
				$actionValue = NotificationPolicyAction::tryFrom($action);
				if ($categoryValue !== null && $actionValue !== null) {
					$actions[$categoryValue->value] = $actionValue;
				}
			}
		}

		$days = $data['new_account_days'] ?? self::DEFAULT_NEW_ACCOUNT_DAYS;

		return new self($actions, is_int($days) ? $days : self::DEFAULT_NEW_ACCOUNT_DAYS);
	}

	public function actionFor(NotificationSenderCategory $category): NotificationPolicyAction
	{
		return $this->actions[$category->value];
	}

	public function withAction(NotificationSenderCategory $category, NotificationPolicyAction $action): self
	{
		$actions = $this->actions;
		$actions[$category->value] = $action;

		return new self($actions, $this->newAccountDays);
	}

	/**
	 * @return array{actions: array<string, string>, new_account_days: int}
	 */
	public function toArray(): array
	{
		$actions = [];
		foreach ($this->actions as $category => $action) {
			$actions[$category] = $action->value;
		}

		return [
			'actions' => $actions,
			'new_account_days' => $this->newAccountDays,
		];
	}
}
