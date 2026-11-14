<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Deletes one record from an AT Protocol PDS.
 *
 * Deletion is a separate capability from writing: a host may use a publisher
 * that only supports `putRecord`, or may deliberately keep deletes disabled.
 * The operation is idempotent at the protocol boundary: "the record is gone"
 * is success, including an already-missing record.
 */
interface AtprotoDeleter
{
	/**
	 * @param string $collection the NSID the record belongs to; the default is a
	 *                           post record
	 *
	 * @throws AtprotoPublishFailedException when the PDS refused the delete or could not be reached
	 */
	public function delete(
		AtprotoAccount $account,
		string $rkey,
		string $collection = AtprotoPostRecord::COLLECTION,
	): void;
}
