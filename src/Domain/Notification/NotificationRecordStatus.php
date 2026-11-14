<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Where one notification sits in the delivery policy.
 *
 * A stored row is never removed by a policy decision; held and dropped rows
 * are the audit of what the policy did. A row written before this feature has
 * no status and is read as visible, which keeps old notifications in place.
 */
enum NotificationRecordStatus: string
{
	case Visible = 'visible';
	case Held = 'held';
	case Dropped = 'dropped';

	public function isVisible(): bool
	{
		return $this === self::Visible;
	}
}
