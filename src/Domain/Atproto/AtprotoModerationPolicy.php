<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Host policy applied before an AT Protocol record is stored or projected.
 *
 * The port is separate from the ActivityPub moderation policy because AT
 * identities are DIDs and labelers are their own trust boundary. A refusal is
 * a skip, never a hardcoded content rewrite.
 */
interface AtprotoModerationPolicy
{
	public function allowsAuthor(string $did): bool;

	public function allowsLabel(AtprotoLabel $label): bool;
}
