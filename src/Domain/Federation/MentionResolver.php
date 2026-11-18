<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Resolves `@user@host` in post text to an actor and its inbox.
 *
 * A mention is not a follow edge. The author may mention an actor that has
 * never followed them, so the follow graph cannot supply the inbox. A host
 * backs this port with WebFinger and a bounded actor fetch; the core only
 * consumes the result and never guesses an inbox from a host name.
 */
interface MentionResolver
{
	/**
	 * @param string $handle the handle without the leading `@`
	 *
	 * @return MentionTarget|null null when the handle is unknown, not a local
	 *                           actor and not a federated actor, or when the
	 *                           remote document has no personal inbox
	 */
	public function resolve(string $handle): ?MentionTarget;
}
