<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Lifecycle of a held sender request.
 *
 * `held` is the request inbox, `allowed` is the explicit allow-list entry
 * that answers every later event, and `dismissed` suppresses later events
 * until the entry is revoked.
 */
enum NotificationSenderStatus: string
{
	case Held = 'held';
	case Allowed = 'allowed';
	case Dismissed = 'dismissed';
}
