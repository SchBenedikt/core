<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoOAuthSession;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefreshException;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionRefresher;
use AlohaSocial\Core\Domain\Atproto\AtprotoSessionStore;
use AlohaSocial\Core\Platform\Clock\Clock;

/**
 * Returns a usable session and rotates it before expiry.
 *
 * The service intentionally has no account-linking or token-format logic. A
 * host resolves its local user id to a stored OAuth session, and this service
 * refreshes and persists the rotated token pair through the host's store.
 */
final class AtprotoSessionService
{
	public const REFRESH_LEEWAY_SECONDS = 60;

	public function __construct(
		private readonly AtprotoSessionStore $sessions,
		private readonly AtprotoSessionRefresher $refresher,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @throws AtprotoSessionRefreshException when an expired session cannot be refreshed
	 */
	public function current(string $localUserId): ?AtprotoOAuthSession
	{
		$session = $this->sessions->find($localUserId);
		if ($session === null) {
			return null;
		}

		if (!$session->needsRefresh($this->clock->now(), self::REFRESH_LEEWAY_SECONDS)) {
			return $session;
		}

		$rotated = $this->refresher->refresh($session);
		if ($rotated->did !== $session->did) {
			throw AtprotoSessionRefreshException::invalidResponse('the refreshed session changed the DID');
		}

		$this->sessions->save($localUserId, $rotated);

		return $rotated;
	}
}
