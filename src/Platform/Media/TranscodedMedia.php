<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Media;

final readonly class TranscodedMedia
{
	public function __construct(
		public string $bytes,
		public string $mimeType,
	) {
		if ($this->bytes === '') {
			throw new \InvalidArgumentException('A transcoded medium needs bytes.');
		}
	}
}
