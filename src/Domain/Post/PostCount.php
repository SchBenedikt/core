<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Optional count capability for a post repository.
 *
 * The instance endpoint only needs the number of live local posts. Keeping
 * this separate from {@see PostRepository} means existing host adapters do not
 * break when they cannot provide a cheap count; an adapter that implements it
 * must exclude soft-deleted rows.
 */
interface PostCount
{
	public function countLive(): int;
}
