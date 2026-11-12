<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * The notification kinds the local compatibility surface can produce.
 *
 * Remote mentions, boosts, poll results and moderation notifications require
 * data models the core does not have yet; they are not accepted as local
 * types until those events exist.
 */
enum NotificationType: string
{
	case Favourite = 'favourite';
	case Follow = 'follow';
}
