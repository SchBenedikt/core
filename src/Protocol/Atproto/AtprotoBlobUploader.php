<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;

/**
 * Stores one binary on the publishing account's PDS.
 *
 * `com.atproto.repo.uploadBlob` needs the media type as the request
 * Content-Type and returns the blob reference the record embeds. The core
 * never contacts a PDS directly: an adapter over the host's HTTP client (and,
 * for an OAuth account, the DPoP proof provider) implements this port.
 */
interface AtprotoBlobUploader
{
	/**
	 * @throws AtprotoBlobException when the PDS refuses or returns no reference
	 */
	public function upload(AtprotoAccount $account, AtprotoMedia $media): AtprotoBlob;
}
