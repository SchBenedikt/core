<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

/**
 * Which replies a list timeline includes.
 *
 * The values are the ones Mastodon clients send. `Followed` keeps replies to
 * accounts the viewer follows, `Members` keeps replies to accounts in the
 * list, and `None` removes every reply.
 */
enum ListRepliesPolicy: string
{
	case Followed = 'followed';

	case Members = 'list';

	case None = 'none';
}
