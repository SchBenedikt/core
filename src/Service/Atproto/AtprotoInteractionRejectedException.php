<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * A like or repost cannot reference an AT Protocol record.
 *
 * Without a published subject record there is nothing to like or repost on
 * the PDS, and a reference without a CID would be unusable. The reason is
 * stable so a host can explain the refusal.
 */
final class AtprotoInteractionRejectedException extends RuntimeException
{
	public const SUBJECT_NOT_PUBLISHED = 'interaction_subject_not_published';

	public const SUBJECT_UNRESOLVABLE = 'interaction_subject_unresolvable';

	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct(match ($reason) {
			self::SUBJECT_NOT_PUBLISHED => 'The post has no published AT Protocol record.',
			self::SUBJECT_UNRESOLVABLE => 'The AT Protocol subject record could not be read from the PDS.',
			default => 'This interaction cannot be published to AT Protocol.',
		});
	}
}
