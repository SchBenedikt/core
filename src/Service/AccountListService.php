<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Timeline\AccountList;
use AlohaSocial\Core\Domain\Timeline\AccountListNotFoundException;
use AlohaSocial\Core\Domain\Timeline\AccountListRepository;
use AlohaSocial\Core\Domain\Timeline\ListRepliesPolicy;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;

use function array_values;
use function count;
use function in_array;
use function is_string;
use function mb_strlen;
use function trim;

/**
 * The local account-list use case.
 *
 * A list is private to its owner. Titles are trimmed and bounded, the replies
 * policy is one of the three Mastodon values, and every membership change is
 * idempotent. Membership is limited to known local account ids by the caller;
 * a remote actor is not an account entity in this local slice.
 */
final class AccountListService
{
	public const MAX_ACCOUNTS_PER_REQUEST = 100;

	public function __construct(
		private readonly AccountListRepository $lists,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	/**
	 * @throws ValidationException
	 */
	public function create(
		string $userId,
		?string $title,
		?string $repliesPolicy = null,
		?bool $exclusive = null,
	): AccountList {
		$userId = $this->owner($userId);
		$now = Time::toSecondPrecision($this->clock->now());

		$list = new AccountList(
			id: 'list-' . $this->ids->generate(),
			userId: $userId,
			title: $this->title($title),
			repliesPolicy: $this->policy($repliesPolicy),
			exclusive: $exclusive ?? false,
			createdAt: $now,
			updatedAt: $now,
		);

		$this->lists->insert($list);

		return $list;
	}

	/**
	 * Applies only the supplied fields. A caller that omits every field gets
	 * the stored list unchanged.
	 *
	 * @throws AccountListNotFoundException
	 * @throws ValidationException
	 */
	public function update(
		string $userId,
		string $id,
		?string $title = null,
		?string $repliesPolicy = null,
		?bool $exclusive = null,
	): AccountList {
		$userId = $this->owner($userId);
		$list = $this->find($userId, $id);

		if ($title === null && $repliesPolicy === null && $exclusive === null) {
			return $list;
		}

		$updated = new AccountList(
			id: $list->id,
			userId: $list->userId,
			title: $title === null ? $list->title : $this->title($title),
			repliesPolicy: $repliesPolicy === null ? $list->repliesPolicy : $this->policy($repliesPolicy),
			exclusive: $exclusive ?? $list->exclusive,
			createdAt: $list->createdAt,
			updatedAt: Time::toSecondPrecision($this->clock->now()),
		);

		if (!$this->lists->update($updated)) {
			throw new AccountListNotFoundException($id);
		}

		return $updated;
	}

	/**
	 * @throws AccountListNotFoundException
	 */
	public function delete(string $userId, string $id): void
	{
		$userId = $this->owner($userId);
		$this->find($userId, $id);

		if (!$this->lists->delete($userId, $id)) {
			throw new AccountListNotFoundException($id);
		}
	}

	/**
	 * @throws AccountListNotFoundException
	 */
	public function find(string $userId, string $id): AccountList
	{
		$userId = $this->owner($userId);
		$list = $this->lists->find($userId, $id);
		if ($list === null) {
			throw new AccountListNotFoundException($id);
		}

		return $list;
	}

	/**
	 * @return list<AccountList>
	 */
	public function lists(string $userId): array
	{
		return $this->lists->lists($this->owner($userId));
	}

	/**
	 * @param list<string> $accountIds
	 *
	 * @throws AccountListNotFoundException
	 * @throws ValidationException
	 */
	public function addMembers(string $userId, string $id, array $accountIds): void
	{
		$userId = $this->owner($userId);
		$this->find($userId, $id);

		$this->lists->addMembers(
			$userId,
			$id,
			$this->accounts($accountIds),
			Time::toSecondPrecision($this->clock->now()),
		);
	}

	/**
	 * @param list<string> $accountIds
	 *
	 * @throws AccountListNotFoundException
	 * @throws ValidationException
	 */
	public function removeMembers(string $userId, string $id, array $accountIds): void
	{
		$userId = $this->owner($userId);
		$this->find($userId, $id);

		$this->lists->removeMembers($userId, $id, $this->accounts($accountIds));
	}

	/**
	 * @return list<string>
	 *
	 * @throws AccountListNotFoundException
	 */
	public function memberIds(string $userId, string $id): array
	{
		$userId = $this->owner($userId);
		$this->find($userId, $id);

		return $this->lists->memberIds($userId, $id);
	}

	/**
	 * @return list<string>
	 */
	public function exclusiveActorIds(string $userId): array
	{
		return $this->lists->exclusiveActorIds($this->owner($userId));
	}

	/**
	 * @throws ValidationException
	 */
	private function owner(string $userId): string
	{
		$userId = trim($userId);
		if ($userId === '' || mb_strlen($userId) > AccountList::MAX_USER_LENGTH) {
			throw new ValidationException(['user' => 'A list needs a local user.']);
		}

		return $userId;
	}

	/**
	 * @throws ValidationException
	 */
	private function title(?string $title): string
	{
		$title = trim((string) $title);
		if ($title === '' || mb_strlen($title) > AccountList::MAX_TITLE_LENGTH) {
			throw new ValidationException([
				'title' => 'A list title must be between 1 and ' . AccountList::MAX_TITLE_LENGTH . ' characters long.',
			]);
		}

		return $title;
	}

	/**
	 * @throws ValidationException
	 */
	private function policy(?string $policy): ListRepliesPolicy
	{
		if ($policy === null) {
			return ListRepliesPolicy::Followed;
		}

		$parsed = ListRepliesPolicy::tryFrom(trim($policy));
		if ($parsed === null) {
			throw new ValidationException([
				'replies_policy' => 'replies_policy must be one of followed, list or none.',
			]);
		}

		return $parsed;
	}

	/**
	 * @param list<string> $accountIds
	 *
	 * @return list<string>
	 *
	 * @throws ValidationException
	 */
	private function accounts(array $accountIds): array
	{
		if ($accountIds === []) {
			throw new ValidationException(['account_ids' => 'Provide at least one account id.']);
		}

		if (count($accountIds) > self::MAX_ACCOUNTS_PER_REQUEST) {
			throw new ValidationException([
				'account_ids' => 'Provide at most ' . self::MAX_ACCOUNTS_PER_REQUEST . ' account ids.',
			]);
		}

		$normalized = [];
		foreach ($accountIds as $accountId) {
			if (!is_string($accountId)) {
				throw new ValidationException(['account_ids' => 'account_ids must contain strings.']);
			}

			$accountId = trim($accountId);
			if ($accountId === '' || mb_strlen($accountId) > AccountList::MAX_ACCOUNT_LENGTH) {
				throw new ValidationException([
					'account_ids' => 'An account id must be between 1 and ' . AccountList::MAX_ACCOUNT_LENGTH . ' characters long.',
				]);
			}

			if (!in_array($accountId, $normalized, true)) {
				$normalized[] = $accountId;
			}
		}

		return array_values($normalized);
	}
}
