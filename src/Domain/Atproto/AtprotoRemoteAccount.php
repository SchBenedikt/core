<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Availability and optional handle for one remote AT Protocol account.
 *
 * Account events are authoritative for revocation: once a DID is inactive,
 * later record events are skipped until an active account event arrives.
 */
final readonly class AtprotoRemoteAccount
{
	public function __construct(
		public string $source,
		public string $did,
		public ?string $handle,
		public AtprotoAccountStatus $status,
		public bool $active,
		public DateTimeImmutable $updatedAt,
	) {
		if ($source === '' || !AtprotoIdentifiers::isDid($did)) {
			throw new InvalidArgumentException('A remote account needs a valid source and DID.');
		}

		if ($handle !== null && !AtprotoIdentifiers::isHandle($handle)) {
			throw new InvalidArgumentException('A remote account handle is invalid.');
		}

		if ($active !== $status->isAvailable()) {
			throw new InvalidArgumentException('A remote account active flag must match its status.');
		}
	}
}
