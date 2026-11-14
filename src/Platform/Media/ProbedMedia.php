<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Media;

use AlohaSocial\Core\Domain\Media\MediaKind;

/**
 * What a probe learned from the bytes themselves.
 *
 * The MIME type is detected, never trusted from the upload envelope. Audio and
 * an unknown video container may legitimately report no dimensions.
 */
final readonly class ProbedMedia
{
	public function __construct(
		public string $mimeType,
		public MediaKind $kind,
		public int $width = 0,
		public int $height = 0,
		public int $durationSeconds = 0,
		public bool $animated = false,
	) {
	}
}
