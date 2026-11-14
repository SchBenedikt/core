<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

/**
 * A reply reference cannot be built from the PDS.
 *
 * The reason is stable so the publishing service can turn it into a post
 * refusal with the same machine-readable code.
 */
final class AtprotoReplyException extends RuntimeException
{
	public const PARENT_UNRESOLVABLE = 'reply_parent_unresolvable';

	public const ROOT_UNRESOLVABLE = 'reply_root_unresolvable';

	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
