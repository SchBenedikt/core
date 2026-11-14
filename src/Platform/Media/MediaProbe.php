<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Media;

/**
 * Reads media metadata from bytes.
 *
 * A host with ffprobe or a richer parser can implement this port; the core
 * ships a dependency-free probe for the formats it accepts. Returning null
 * means the bytes are not a supported medium, and the upload is refused
 * before any blob is written.
 */
interface MediaProbe
{
	public function probe(string $bytes): ?ProbedMedia;
}
