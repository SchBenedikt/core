<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Image;

use AlohaSocial\Core\Domain\Media\Attachment;
use GdImage;

use function function_exists;
use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecolorallocatealpha;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefill;
use function imagejpeg;
use function imagepng;
use function imagesavealpha;
use function imagewebp;
use function is_array;
use function max;
use function min;
use function ob_get_clean;
use function ob_start;
use function round;

/**
 * The local image processor.
 *
 * GD is already the portable image extension available to both hosts; the
 * original bytes are stored untouched and this class only emits the derived
 * thumbnail. Decoding is done before any write, so corrupt input cannot leave
 * a row or a blob behind.
 */
final class GdImageProcessor implements ImageProcessor
{
	public function thumbnail(string $bytes, int $maxWidth, int $maxHeight): ProcessedImage
	{
		if ($maxWidth < 1 || $maxHeight < 1) {
			throw new ImageProcessingException('Thumbnail bounds must be positive.');
		}

		if (!function_exists('imagecreatefromstring')) {
			throw new ImageProcessingException('The GD extension is not available.');
		}

		$info = @getimagesizefromstring($bytes);
		if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
			throw new ImageProcessingException('The upload is not a decodable image.');
		}

		$width = (int) $info[0];
		$height = (int) $info[1];
		$mimeType = (string) $info['mime'];

		if ($width < 1 || $height < 1
			|| $width > Attachment::MAX_DIMENSION
			|| $height > Attachment::MAX_DIMENSION
			|| $width * $height > Attachment::MAX_PIXELS
		) {
			throw new ImageProcessingException('The image is too large to decode safely.');
		}

		$source = @imagecreatefromstring($bytes);
		if (!$source instanceof GdImage) {
			throw new ImageProcessingException('The upload is not a decodable image.');
		}

		try {
			return $this->resize($source, $width, $height, $mimeType, $maxWidth, $maxHeight);
		} finally {
			imagedestroy($source);
		}
	}

	private function resize(
		GdImage $source,
		int $width,
		int $height,
		string $mimeType,
		int $maxWidth,
		int $maxHeight,
	): ProcessedImage {
		$scale = min(1, $maxWidth / $width, $maxHeight / $height);
		$targetWidth = max(1, (int) round($width * $scale));
		$targetHeight = max(1, (int) round($height * $scale));

		$target = imagecreatetruecolor($targetWidth, $targetHeight);
		if (!$target instanceof GdImage) {
			throw new ImageProcessingException('The thumbnail canvas could not be created.');
		}

		try {
			if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
				imagealphablending($target, false);
				imagesavealpha($target, true);
				$transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
				imagefill($target, 0, 0, $transparent);
				imagealphablending($target, true);
			} else {
				$white = imagecolorallocate($target, 255, 255, 255);
				imagefill($target, 0, 0, $white);
			}

			imagecopyresampled(
				$target,
				$source,
				0,
				0,
				0,
				0,
				$targetWidth,
				$targetHeight,
				$width,
				$height,
			);

			ob_start();
			$encoded = match ($mimeType) {
				'image/png' => imagepng($target, null, 6),
				'image/webp' => function_exists('imagewebp') && imagewebp($target, null, 85),
				'image/jpeg' => imagejpeg($target, null, 85),
				default => false,
			};
			$output = ob_get_clean();

			if ($encoded === false || !is_string($output) || $output === '') {
				throw new ImageProcessingException('The thumbnail could not be encoded.');
			}

			return new ProcessedImage($output, $mimeType, $targetWidth, $targetHeight);
		} finally {
			imagedestroy($target);
		}
	}
}
