<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Image;

use RuntimeException;

/**
 * An image that could not be decoded, resized or encoded.
 *
 * The media service turns this into a client validation error; it never
 * becomes a half-written attachment row.
 */
final class ImageProcessingException extends RuntimeException
{
}
