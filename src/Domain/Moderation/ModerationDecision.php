<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * What a moderator decided. Rejecting is a separate service call; a resolved
 * report always names one of these outcomes.
 */
enum ModerationDecision: string
{
	case NoAction = 'no_action';
	case SilenceActor = 'silence_actor';
	case SuspendActor = 'suspend_actor';
	case BlockDomain = 'block_domain';

	public static function fromInput(string $value): ?self
	{
		return self::tryFrom(strtolower(trim($value)));
	}
}
