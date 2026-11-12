<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\Block;

/**
 * The outcome of a block request.
 *
 * `created` is false when the actor was already blocked; the handler uses it
 * to answer 201 or 200 without a second lookup.
 */
final readonly class BlockResult
{
	public function __construct(
		public Block $block,
		public bool $created,
	) {
	}
}
