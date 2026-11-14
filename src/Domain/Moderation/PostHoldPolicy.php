<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * Decides whether a new local post needs review before publication.
 *
 * The port is deliberately small: a host implements first-post, spam-score or
 * allow-list rules outside the core and returns a stable reason string. No
 * policy means no hold, which is the safe default for existing installs.
 */
interface PostHoldPolicy
{
	public function holdReason(string $authorId, string $content): ?string;
}
