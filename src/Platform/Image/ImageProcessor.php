<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Image;

/**
 * Produces a bounded timeline variant without changing the original bytes.
 *
 * A host may replace this with an ImageMagick/libvips adapter later. The
 * local GD implementation is the complete default for the standalone host.
 */
interface ImageProcessor
{
	/**
	 * @throws ImageProcessingException when the bytes are not a decodable image
	 */
	public function thumbnail(string $bytes, int $maxWidth, int $maxHeight): ProcessedImage;
}
