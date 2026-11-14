<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * The complete, auditable result of applying the notification policy to one
 * event. The reason is a stable short token; it lets a moderator or the sender
 * request list explain why an event was held or dropped without exposing any
 * content.
 */
final readonly class NotificationDecision
{
	public function __construct(
		public NotificationPolicyAction $action,
		public NotificationSenderCategory $category,
		public string $reason,
	) {
	}
}
