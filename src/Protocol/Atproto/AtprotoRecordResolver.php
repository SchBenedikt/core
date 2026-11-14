<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Reads one record from a PDS.
 *
 * Reply references need the `cid` of the parent and the root of a parent that
 * is itself a reply. Guessing or omitting a CID produces a record the AppView
 * cannot thread, so the builder resolves it through this port.
 */
interface AtprotoRecordResolver
{
	/**
	 * @return AtprotoRecord|null `null` when the record is missing or unreadable
	 */
	public function getRecord(string $pdsUrl, string $uri): ?AtprotoRecord;
}
