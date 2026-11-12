<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A remote media import failed for one attachment.
 *
 * The reason is stable and documented in `docs/guides/media.md`; the message
 * is for operators and may contain the status code, but never response bytes.
 */
final class RemoteMediaException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
