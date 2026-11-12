<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Exchanges one rotating refresh token for a new AT Protocol OAuth session.
 *
 * The implementation must treat the old refresh token as consumed after a
 * successful exchange and return the rotated token. Callers persist the
 * returned session before using its access token.
 */
interface AtprotoSessionRefresher
{
	/**
	 * @throws AtprotoSessionRefreshException when the session cannot be refreshed
	 */
	public function refresh(AtprotoOAuthSession $session): AtprotoOAuthSession;
}
