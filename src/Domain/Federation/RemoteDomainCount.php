<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Optional count capability for the remote-post store.
 *
 * Mastodon's `domain_count` is a public summary, not a directory of peers. A
 * repository that can cheaply derive it from live remote posts implements this
 * capability; a repository without it keeps the field at zero. The count is
 * distinct hosts, not activities or actors, and excludes soft-deleted rows.
 */
interface RemoteDomainCount
{
	public function remoteDomainCount(): int;
}
