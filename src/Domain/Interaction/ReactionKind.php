<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

/**
 * The reaction kinds that have their own identity and count.
 *
 * A like and an emoji reaction are never folded into one row: the same actor
 * may like a post and add an emoji, and the two counts stay separate. A
 * `dislike` is intentionally absent: no supported protocol negotiates it, and
 * a local-only dislike would look like a federated one.
 */
enum ReactionKind: string
{
	case Like = 'like';

	case Emoji = 'emoji';
}
