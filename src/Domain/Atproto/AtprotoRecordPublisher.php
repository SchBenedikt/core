<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/** Publisher variant that preserves the PDS-assigned content identifier. */
interface AtprotoRecordPublisher extends AtprotoPublisher
{
	/**
	 * @param array<string, mixed> $record
	 * @throws AtprotoPublishFailedException
	 */
	public function publishRecord(
		AtprotoAccount $account,
		string $rkey,
		array $record,
		string $collection = AtprotoPostRecord::COLLECTION,
	): AtprotoPublishedRecord;
}
