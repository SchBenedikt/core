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
 * The values are the names Mastodon clients already understand. A reply and
 * every direct recipient are a mention; a local boost is a reblog. Poll
 * results and moderation notifications need events the core does not model
 * yet and are therefore not accepted here.
 */
enum NotificationType: string
{
	case Favourite = 'favourite';
	case Follow = 'follow';
	case Mention = 'mention';
	case Reblog = 'reblog';
}
