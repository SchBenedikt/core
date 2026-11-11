<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Answers whether a handle belongs to an account on this instance.
 *
 * The core has no user table of its own: accounts are a host concern (the
 * Nextcloud user backend, the standalone account store). This port is the
 * smallest thing an actor document needs from it — enough to answer 404 for
 * an unknown handle instead of publishing an actor that nobody can use.
 */
interface LocalActorDirectory
{
	public function has(string $userId): bool;
}
