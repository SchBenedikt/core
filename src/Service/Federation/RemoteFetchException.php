<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use RuntimeException;

/**
 * A bounded fetch of a remote document failed.
 *
 * Callers prefix `reason` with their own domain (`remote_media_…`,
 * `thread_…`) so the documented reason stays stable for each feature while the
 * URL, DNS, redirect and body-limit rules live in one place.
 */
final class RemoteFetchException extends RuntimeException
{
	public function __construct(
		public readonly string $reason,
		string $message,
	) {
		parent::__construct($message);
	}
}
