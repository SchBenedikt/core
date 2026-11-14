<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Optional downstream delivery of a notification the policy allowed.
 *
 * A host implements this for its bell, digest, email or push adapter. The
 * sink is called exactly once when a visible row is recorded, never for a
 * held or dropped row, and never again when a held sender is accepted:
 * accepting releases stored rows without re-notifying.
 */
interface NotificationEventSink
{
	public function emit(Notification $notification): void;
}
