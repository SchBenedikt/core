<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Atproto\AtprotoLabel;
use AlohaSocial\Core\Domain\Atproto\AtprotoModerationPolicy;

final class DenyingAtprotoModerationPolicy implements AtprotoModerationPolicy
{
	/**
	 * @param list<string> $blockedAuthors
	 * @param list<string> $blockedLabelers
	 */
	public function __construct(
		private readonly array $blockedAuthors = [],
		private readonly array $blockedLabelers = [],
	) {
	}

	public function allowsAuthor(string $did): bool
	{
		return !in_array($did, $this->blockedAuthors, true);
	}

	public function allowsLabel(AtprotoLabel $label): bool
	{
		return !in_array($label->labelerDid, $this->blockedLabelers, true);
	}
}
