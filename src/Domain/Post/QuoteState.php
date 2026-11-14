<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * The lifecycle of a quote.
 *
 * A local quote of another local post starts accepted, because the grant can
 * be issued synchronously. A quote of a remote post starts pending and waits
 * for the quoted author's Accept or Reject. Revoked is the quoted author's
 * later withdrawal of a grant that was already accepted.
 */
enum QuoteState: string
{
	case Pending = 'pending';

	case Accepted = 'accepted';

	case Rejected = 'rejected';

	case Revoked = 'revoked';

	public function isAccepted(): bool
	{
		return $this === self::Accepted;
	}

	/**
	 * Whether the quote still points at its target in public output.
	 */
	public function showsTarget(): bool
	{
		return $this === self::Accepted;
	}

	public function isFinal(): bool
	{
		return $this === self::Rejected || $this === self::Revoked;
	}
}
