<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Resolves a reply to the root a conversation mute is keyed by.
 *
 * A resolver returns null when the root is unknown instead of guessing; the
 * notification is then stored without a conversation id and no mute applies.
 * Implementations must be bounded and cycle-safe.
 */
interface NotificationThreadResolver
{
	public function rootFor(string $postId): ?string;
}
