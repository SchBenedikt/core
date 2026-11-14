<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * A held post is not a Post yet: it has no public id, no delivery job and no
 * timeline row until a moderator approves it.
 */
enum HeldPostStatus: string
{
	case Pending = 'pending';
	case Reviewing = 'reviewing';
	case Approved = 'approved';
	case Rejected = 'rejected';
}
