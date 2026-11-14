<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

/**
 * The text cannot be turned into a complete facet set.
 *
 * A mention whose handle does not resolve is refused rather than written
 * without a DID: a broken mention is invisible in the AppView and makes the
 * record look correct while it is not.
 */
final class AtprotoFacetException extends RuntimeException
{
	public const MENTION_UNRESOLVED = 'mention_unresolved';

	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
