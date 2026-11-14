<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;

/**
 * Reads a Bluesky timeline through an AppView.
 *
 * This is a read port, not a second post store: the host decides whether to
 * render the items, cache them or map them onto local accounts. The account
 * supplies the PDS and token; the AppView itself is addressed by the adapter.
 */
interface AtprotoTimelineReader
{
	/**
	 * @throws AtprotoTimelineException when the AppView refuses, is unreachable or answers unusably
	 */
	public function getTimeline(
		AtprotoAccount $account,
		int $limit = self::DEFAULT_LIMIT,
		?string $cursor = null,
	): AtprotoTimelinePage;

	public const DEFAULT_LIMIT = 30;
}
