<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * The instance-wide answer used by ingest, delivery and local timelines.
 *
 * It is separate from the per-reader block list: a policy refusal applies to
 * every local account and is the moderator's decision, not a preference.
 */
interface ModerationPolicy
{
	public function allowsActor(string $actorId): bool;

	public function allowsDomain(string $domain): bool;
}
