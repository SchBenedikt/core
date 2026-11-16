<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use InvalidArgumentException;

use function trim;

/**
 * The durable subject a job acts for.
 *
 * Account jobs carry `account`/`local-user-id`, so a deletion can cancel them
 * without guessing from a payload or relying on an owner column in the queue.
 * Jobs without a reliable subject stay global and are not cancelled by one
 * account's deletion.
 */
final readonly class JobSubject
{
	public const ACCOUNT = 'account';

	public function __construct(
		public string $type,
		public string $id,
	) {
		if (trim($this->type) === '' || trim($this->id) === '') {
			throw new InvalidArgumentException('A job subject needs a type and an id.');
		}
	}

	public static function account(string $userId): self
	{
		return new self(self::ACCOUNT, $userId);
	}

	public function isAccount(): bool
	{
		return $this->type === self::ACCOUNT;
	}
}
