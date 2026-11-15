<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * A normalized event from a relay, Jetstream or AppView poll.
 *
 * Record commits carry repository operations; account events carry identity
 * availability. Keeping them distinct prevents an account takedown from being
 * mistaken for a record delete.
 */
enum AtprotoEventKind: string
{
	case Record = 'record';

	case Account = 'account';
}
