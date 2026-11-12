<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Optional count capability for a local account directory.
 *
 * The base {@see LocalActorDirectory} port only needs to answer whether one id
 * exists. A host that can cheaply count its accounts also implements this
 * interface so the public instance endpoint can report `user_count` without
 * loading every account into the core. A directory that omits it leaves the
 * count at zero rather than inventing a number.
 */
interface LocalActorCount
{
	public function localActorCount(): int;
}
