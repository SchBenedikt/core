<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * A host-owned relay, Jetstream, or AppView event source.
 *
 * The host owns service URLs, OAuth tokens, the WebSocket/HTTP transport and
 * its connection lifecycle. It normalizes whatever it reads into
 * {@see AtprotoEvent} values and hands the core one bounded page at a time.
 * The cursor is opaque to the core and must round-trip unchanged.
 */
interface AtprotoEventSource
{
	public function name(): string;

	/**
	 * @param int $limit a hint; adapters may return fewer, never an unbounded page
	 *
	 * @throws \Throwable when the source is unreachable or answers unusably
	 */
	public function pull(?string $cursor, int $limit): AtprotoEventPage;

	/**
	 * Reports the source's own health. A host adapter that has no separate
	 * health endpoint can return `live`; transport failures are still surfaced
	 * by `pull()`.
	 */
	public function status(): AtprotoSourceStatus;
}
