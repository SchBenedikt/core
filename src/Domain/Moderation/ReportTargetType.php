<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * What a report points at. A domain target is a report about a whole server,
 * not about one of its actors.
 */
enum ReportTargetType: string
{
	case Post = 'post';
	case Actor = 'actor';
	case Domain = 'domain';

	public static function fromInput(string $value): ?self
	{
		return self::tryFrom(strtolower(trim($value)));
	}
}
