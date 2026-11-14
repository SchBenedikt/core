<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * Why a local account or a remote actor reported something.
 *
 * The values follow the Mastodon report categories so a host can map them
 * without inventing a second taxonomy. `Other` is the safe fallback for a
 * peer that sends a free-form Flag.
 */
enum ReportReason: string
{
	case Spam = 'spam';
	case Harassment = 'harassment';
	case Hate = 'hate';
	case Violence = 'violence';
	case Misinformation = 'misinformation';
	case Legal = 'legal';
	case Other = 'other';

	public static function fromInput(string $value): ?self
	{
		return self::tryFrom(strtolower(trim($value)));
	}
}
