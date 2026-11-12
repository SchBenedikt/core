<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Image;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagedestroy;
use function ob_get_clean;
use function ob_start;

final class ImageFixture
{
	public static function png(int $width, int $height): string
	{
		$image = imagecreatetruecolor($width, $height);
		$color = imagecolorallocate($image, 30, 90, 170);
		imagefill($image, 0, 0, $color);

		ob_start();
		imagepng($image);
		$bytes = ob_get_clean();
		imagedestroy($image);

		return $bytes;
	}
}
