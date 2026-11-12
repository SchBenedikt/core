<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
	public function testExposesTheApiShapeWithoutAStorageKey(): void
	{
		$attachment = $this->attachment();

		$shape = $attachment->toArray();

		self::assertSame('a1', $shape['id']);
		self::assertSame('image', $shape['media_type']);
		self::assertSame('image/png', $shape['mime_type']);
		self::assertSame(800, $shape['width']);
		self::assertSame(600, $shape['height']);
		self::assertSame(1234, $shape['byte_size']);
		self::assertSame('a description', $shape['alt_text']);
		self::assertSame('2026-11-11T10:00:00Z', $shape['created_at']);
		self::assertArrayNotHasKey('storage_key', $shape);
		self::assertArrayNotHasKey('thumbnail_key', $shape);
	}

	public function testWithAltTextKeepsTheBytesAndStorageKeys(): void
	{
		$attachment = $this->attachment();
		$updated = $attachment->withAltText('new description');

		self::assertSame('new description', $updated->altText);
		self::assertSame($attachment->id, $updated->id);
		self::assertSame($attachment->storageKey, $updated->storageKey);
		self::assertSame($attachment->thumbnailKey, $updated->thumbnailKey);
		self::assertSame($attachment->byteSize, $updated->byteSize);
		self::assertSame('a description', $attachment->altText, 'The original object stays immutable.');
	}

	public function testRejectsAnUnsupportedMimeType(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Attachment(
			'a1',
			'alice',
			'media/a1/original.svg',
			'media/a1/thumbnail.svg',
			'image/svg+xml',
			800,
			600,
			1234,
			null,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	public function testRejectsAnInvalidDimension(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Attachment(
			'a1',
			'alice',
			'media/a1/original.png',
			'media/a1/thumbnail.png',
			'image/png',
			0,
			600,
			1234,
			null,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	public function testRejectsAnOversizedFile(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Attachment(
			'a1',
			'alice',
			'media/a1/original.png',
			'media/a1/thumbnail.png',
			'image/png',
			800,
			600,
			Attachment::MAX_BYTES + 1,
			null,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	public function testRejectsAnOversizedAltText(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Attachment(
			'a1',
			'alice',
			'media/a1/original.png',
			'media/a1/thumbnail.png',
			'image/png',
			800,
			600,
			1234,
			str_repeat('a', Attachment::MAX_ALT_TEXT_LENGTH + 1),
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	private function attachment(): Attachment
	{
		return new Attachment(
			'a1',
			'alice',
			'media/a1/original.png',
			'media/a1/thumbnail.png',
			'image/png',
			800,
			600,
			1234,
			'a description',
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
