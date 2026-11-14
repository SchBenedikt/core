<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * The five relationship buckets a notification policy can address.
 *
 * `mutual`, `following` and `follower` come from the follow graph. `new` is a
 * sender without a relationship whose account is younger than the policy
 * threshold; `stranger` is everyone the core cannot classify more closely.
 */
enum NotificationSenderCategory: string
{
	case Mutual = 'mutual';
	case Following = 'following';
	case Follower = 'follower';
	case New = 'new';
	case Stranger = 'stranger';
}
