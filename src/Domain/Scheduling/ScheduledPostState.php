<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

/**
 * Where a scheduled post is in its lifecycle.
 *
 * `Publishing` is the lease a worker holds between claiming a due row and
 * finishing the canonical post write. It is internal: the API keeps
 * presenting it as `scheduled`, because a reader must not be told twice that
 * the same post is about to appear. A lease that outlives its expiry is
 * claimable again, which is how a crashed worker is recovered without a
 * second publication: the reserved post id makes the retry idempotent.
 */
enum ScheduledPostState: string
{
	case Scheduled = 'scheduled';
	case Publishing = 'publishing';
	case Published = 'published';
	case Cancelled = 'cancelled';
	case Failed = 'failed';

	/**
	 * Whether the row can still be published by a worker.
	 */
	public function isPending(): bool
	{
		return $this === self::Scheduled || $this === self::Publishing;
	}

	/**
	 * The state the API shows. A claimed row is still a scheduled post.
	 */
	public function toApiValue(): string
	{
		return $this === self::Publishing ? self::Scheduled->value : $this->value;
	}

	/**
	 * @throws \InvalidArgumentException for a value outside the contract
	 */
	public static function fromString(string $value): self
	{
		$state = self::tryFrom($value);
		if ($state === null) {
			throw new \InvalidArgumentException('Unknown scheduled post state: ' . $value);
		}

		return $state;
	}
}
