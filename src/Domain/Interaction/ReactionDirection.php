<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

/**
 * Which side of the federation a reaction belongs to.
 *
 * `in` is a remote actor reacting to a local post; the subject is the actor
 * URL. `out` is a local account reacting to a remote object; the subject is
 * the local user id. One table, because the identity rule (subject, object,
 * kind, emoji) is the same in both directions.
 */
enum ReactionDirection: string
{
	case In = 'in';

	case Out = 'out';
}
