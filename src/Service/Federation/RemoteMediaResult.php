<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

/**
 * The bytes and optional response content type of a fetched remote attachment.
 */
final readonly class RemoteMediaResult
{
	public function __construct(
		public string $body,
		public ?string $contentType = null,
	) {
	}
}
