<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;
use Throwable;

/**
 * An inbound AT Protocol sync failed for a stable, reportable reason.
 */
final class AtprotoSyncException extends RuntimeException
{
	public const SOURCE_UNAVAILABLE = 'atproto_source_unavailable';

	public const SOURCE_MISMATCH = 'atproto_source_mismatch';

	public const INVALID_CURSOR = 'atproto_invalid_cursor';

	public const IDENTITY_CONFLICT = 'atproto_identity_conflict';

	public const STORE_FAILED = 'atproto_store_failed';

	public function __construct(
		public readonly string $reason,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
