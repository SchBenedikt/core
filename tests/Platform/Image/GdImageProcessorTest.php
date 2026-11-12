<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Image;

use AlohaSocial\Core\Platform\Image\GdImageProcessor;
use AlohaSocial\Core\Platform\Image\ImageProcessingException;
use PHPUnit\Framework\TestCase;

final class GdImageProcessorTest extends TestCase
{
	public function testScalesTheLongestSideWithoutChangingTheOriginalBytes(): void
	{
		$image = ImageFixture::png(1200, 800);
		$original = $image;

		$thumbnail = (new GdImageProcessor())->thumbnail($image, 640, 640);

		self::assertSame('image/png', $thumbnail->mimeType);
		self::assertSame(640, $thumbnail->width);
		self::assertSame(427, $thumbnail->height);
		self::assertNotSame($original, $thumbnail->bytes);
		self::assertSame($original, $image);
	}

	public function testDoesNotUpscaleASmallImage(): void
	{
		$thumbnail = (new GdImageProcessor())->thumbnail(ImageFixture::png(100, 50), 640, 640);

		self::assertSame(100, $thumbnail->width);
		self::assertSame(50, $thumbnail->height);
	}

	public function testCorruptBytesAreRejected(): void
	{
		$this->expectException(ImageProcessingException::class);

		(new GdImageProcessor())->thumbnail('not an image', 640, 640);
	}

	public function testNonPositiveBoundsAreRejected(): void
	{
		$this->expectException(ImageProcessingException::class);

		(new GdImageProcessor())->thumbnail(ImageFixture::png(10, 10), 0, 640);
	}
}
