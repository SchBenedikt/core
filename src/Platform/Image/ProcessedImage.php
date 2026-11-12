<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Image;

use InvalidArgumentException;

final readonly class ProcessedImage
{
	public function __construct(
		public string $bytes,
		public string $mimeType,
		public int $width,
		public int $height,
	) {
		if ($bytes === '' || $width < 1 || $height < 1) {
			throw new InvalidArgumentException('A processed image needs bytes and dimensions.');
		}
	}
}
