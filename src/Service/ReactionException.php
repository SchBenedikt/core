<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A refused outbound reaction.
 *
 * The reason is stable because HTTP handlers, hosts and logs map it; the
 * message is for a human. `remote_post_not_found` deliberately covers both a
 * missing object and one whose author the caller does not follow, so a caller
 * cannot probe which remote posts this instance stores.
 */
final class ReactionException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
