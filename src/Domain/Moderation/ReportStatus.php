<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * The durable state of a report.
 *
 * `Reviewing` is the short-lived claim a moderator takes before an action is
 * written. It makes a concurrent approval lose cleanly instead of applying
 * two suspensions for one report.
 */
enum ReportStatus: string
{
	case Pending = 'pending';
	case Reviewing = 'reviewing';
	case Resolved = 'resolved';
	case Rejected = 'rejected';

	public function isOpen(): bool
	{
		return $this === self::Pending || $this === self::Reviewing;
	}
}
