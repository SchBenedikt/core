<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Writes one record to an AT Protocol PDS.
 *
 * Implementations own transport details and credentials. The port takes an
 * already validated record array so the record shape can be tested without a
 * network, and returns the canonical AT URI the PDS assigned.
 */
interface AtprotoPublisher
{
	/**
	 * @param array<string, mixed> $record
	 *
	 * @throws AtprotoPublishFailedException
	 */
	public function publish(AtprotoAccount $account, string $rkey, array $record): string;
}
