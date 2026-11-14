<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * The durable policy an instance applies to an actor or a whole domain.
 *
 * A silence hides the actor from local audiences but keeps its content
 * stored; a suspension refuses it at ingest and delivery. A domain block
 * applies to every actor whose URL uses that host.
 */
enum ModerationActionKind: string
{
	case SilenceActor = 'silence_actor';
	case SuspendActor = 'suspend_actor';
	case BlockDomain = 'block_domain';

	public static function fromDecision(ModerationDecision $decision): ?self
	{
		return match ($decision) {
			ModerationDecision::SilenceActor => self::SilenceActor,
			ModerationDecision::SuspendActor => self::SuspendActor,
			ModerationDecision::BlockDomain => self::BlockDomain,
			ModerationDecision::NoAction => null,
		};
	}
}
