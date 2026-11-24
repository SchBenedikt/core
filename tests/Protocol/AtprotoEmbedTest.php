<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlob;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobUploader;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbed;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbedException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use PHPUnit\Framework\TestCase;

final class AtprotoEmbedTest extends TestCase
{
	public function testOneImageBecomesAnImagesEmbed(): void
	{
		$uploader = $this->uploader();
		$embed = (new AtprotoEmbed($uploader))->build(
			[new AtprotoMedia('image/png', 'png-bytes', 'a cat', 800, 600)],
			$this->account(),
		);

		self::assertSame([
			'$type' => 'app.bsky.embed.images',
			'images' => [[
				'image' => [
					'$type' => 'blob',
					'ref' => ['$link' => 'bafy-1'],
					'mimeType' => 'image/png',
					'size' => 9,
				],
				'alt' => 'a cat',
				'aspectRatio' => ['width' => 800, 'height' => 600],
			]],
		], $embed);
		self::assertCount(1, $uploader->uploaded);
	}

	public function testUpToFourImagesAreEmbeddedInOrder(): void
	{
		$media = [];
		for ($index = 1; $index <= 4; $index++) {
			$media[] = new AtprotoMedia('image/jpeg', 'jpeg-' . $index, 'image ' . $index);
		}

		$embed = (new AtprotoEmbed($this->uploader()))->build($media, $this->account());

		self::assertNotNull($embed);
		self::assertSame(
			['bafy-1', 'bafy-2', 'bafy-3', 'bafy-4'],
			array_map(static fn (array $image): string => $image['image']['ref']['$link'], $embed['images']),
		);
		self::assertSame('image 1', $embed['images'][0]['alt']);
	}

	public function testFiveImagesAreRefusedWithoutUploading(): void
	{
		$uploader = $this->uploader();
		$media = [];
		for ($index = 1; $index <= 5; $index++) {
			$media[] = new AtprotoMedia('image/jpeg', 'jpeg', 'alt');
		}

		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($uploader))->build($media, $this->account());
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::TOO_MANY_IMAGES, $exception->reason);
			self::assertCount(0, $uploader->uploaded);

			throw $exception;
		}
	}

	public function testAVideoBecomesAVideoEmbed(): void
	{
		$embed = (new AtprotoEmbed($this->uploader()))->build(
			[new AtprotoMedia('video/mp4', 'mp4-bytes', 'a clip', 1920, 1080)],
			$this->account(),
		);

		self::assertSame([
			'$type' => 'app.bsky.embed.video',
			'video' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafy-1'],
				'mimeType' => 'video/mp4',
				'size' => 9,
			],
			'alt' => 'a clip',
			'aspectRatio' => ['width' => 1920, 'height' => 1080],
		], $embed);
	}

	public function testAVideoWithoutAltOmitsTheField(): void
	{
		$embed = (new AtprotoEmbed($this->uploader()))->build(
			[new AtprotoMedia('video/mp4', 'mp4-bytes')],
			$this->account(),
		);

		self::assertNotNull($embed);
		self::assertArrayNotHasKey('alt', $embed);
		self::assertArrayNotHasKey('aspectRatio', $embed);
	}

	public function testAMixedPostIsRefusedWithoutUploading(): void
	{
		$uploader = $this->uploader();

		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($uploader))->build([
				new AtprotoMedia('image/png', 'png', 'alt'),
				new AtprotoMedia('video/mp4', 'mp4', 'alt'),
			], $this->account());
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::MIXED_MEDIA, $exception->reason);
			self::assertCount(0, $uploader->uploaded);

			throw $exception;
		}
	}

	public function testTwoVideosAreRefused(): void
	{
		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($this->uploader()))->build([
				new AtprotoMedia('video/mp4', 'mp4', 'alt'),
				new AtprotoMedia('video/mp4', 'mp4', 'alt'),
			], $this->account());
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::TOO_MANY_VIDEOS, $exception->reason);

			throw $exception;
		}
	}

	public function testAnImageWithoutAltIsRefused(): void
	{
		$uploader = $this->uploader();

		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($uploader))->build(
				[new AtprotoMedia('image/png', 'png')],
				$this->account(),
			);
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::ALT_MISSING, $exception->reason);
			self::assertCount(0, $uploader->uploaded);

			throw $exception;
		}
	}

	public function testAnEmptyAltIsAllowed(): void
	{
		$embed = (new AtprotoEmbed($this->uploader()))->build(
			[new AtprotoMedia('image/png', 'png', '')],
			$this->account(),
		);

		self::assertNotNull($embed);
		self::assertSame('', $embed['images'][0]['alt']);
	}

	public function testAnOverlongAltIsRefused(): void
	{
		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($this->uploader()))->build(
				[new AtprotoMedia('image/png', 'png', str_repeat('a', AtprotoEmbed::MAX_ALT_LENGTH + 1))],
				$this->account(),
			);
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::ALT_TOO_LONG, $exception->reason);

			throw $exception;
		}
	}

	public function testAnUnsupportedImageTypeIsRefusedWithoutUploading(): void
	{
		$uploader = $this->uploader();

		$this->expectException(AtprotoBlobException::class);

		try {
			(new AtprotoEmbed($uploader))->build(
				[new AtprotoMedia('image/gif', 'gif', 'alt')],
				$this->account(),
			);
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::MIME_UNSUPPORTED, $exception->reason);
			self::assertCount(0, $uploader->uploaded);

			throw $exception;
		}
	}

	public function testAnOversizedImageIsRefusedWithoutUploading(): void
	{
		$uploader = $this->uploader();

		$this->expectException(AtprotoBlobException::class);

		try {
			(new AtprotoEmbed($uploader))->build(
				[new AtprotoMedia('image/png', str_repeat('a', AtprotoEmbed::MAX_IMAGE_BYTES + 1), 'alt')],
				$this->account(),
			);
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::TOO_LARGE, $exception->reason);
			self::assertCount(0, $uploader->uploaded);

			throw $exception;
		}
	}

	public function testEmptyMediaIsRefused(): void
	{
		$this->expectException(AtprotoBlobException::class);

		try {
			(new AtprotoEmbed($this->uploader()))->build(
				[new AtprotoMedia('image/png', '', 'alt')],
				$this->account(),
			);
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::MISSING, $exception->reason);

			throw $exception;
		}
	}

	public function testAnIncompleteAspectRatioIsRefused(): void
	{
		$uploader = $this->uploader();
		$this->expectException(AtprotoEmbedException::class);

		try {
			(new AtprotoEmbed($uploader))->build(
				[
					new AtprotoMedia('image/png', 'valid image', 'alt'),
					new AtprotoMedia('image/png', 'invalid image', 'alt', 800, null),
				],
				$this->account(),
			);
		} catch (AtprotoEmbedException $exception) {
			self::assertSame(AtprotoEmbedException::ASPECT_RATIO_INVALID, $exception->reason);
			self::assertCount(0, $uploader->uploaded, 'Invalid metadata must be rejected before uploading any image.');

			throw $exception;
		}
	}

	public function testNoMediaIsNoEmbed(): void
	{
		self::assertNull((new AtprotoEmbed($this->uploader()))->build([], $this->account()));
	}

	private function uploader(): AtprotoBlobUploader
	{
		return new class implements AtprotoBlobUploader {
			/** @var list<AtprotoMedia> */
			public array $uploaded = [];

			public function upload(AtprotoAccount $account, AtprotoMedia $media): AtprotoBlob
			{
				$this->uploaded[] = $media;

				return new AtprotoBlob('bafy-' . count($this->uploaded), $media->mimeType, $media->size());
			}
		};
	}

	private function account(): AtprotoAccount
	{
		return new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token');
	}
}
