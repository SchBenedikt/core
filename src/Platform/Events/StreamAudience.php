<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Events;

/** Visibility class used by host brokers when filtering stream events. */
enum StreamAudience: string
{
	case Public = 'public';
	case Followers = 'followers';
	case Direct = 'direct';
}
