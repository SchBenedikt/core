<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use RuntimeException;

/**
 * A blob cannot be uploaded to the PDS.
 *
 * The reason is stable so the publishing service can refuse the post with the
 * same code instead of writing a record whose embed points at nothing.
 */
final class AtprotoBlobException extends RuntimeException
{
	public const MIME_UNSUPPORTED = 'blob_mime_unsupported';

	public const TOO_LARGE = 'blob_too_large';

	public const MISSING = 'blob_missing';

	public const UPLOAD_FAILED = 'blob_upload_failed';

	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
