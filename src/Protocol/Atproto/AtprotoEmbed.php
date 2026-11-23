<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use InvalidArgumentException;

use function count;
use function in_array;
use function mb_strlen;

/**
 * Builds `app.bsky.embed.images` or `app.bsky.embed.video` from media input.
 *
 * Everything is validated before the first upload: an unsupported type, an
 * oversized file, a mixed image/video post or a missing alt text refuses the
 * post without storing a single blob. Only then are the blobs uploaded through
 * `AtprotoBlobUploader` and referenced with their PDS blob reference.
 *
 * Decisions: images require an alt text, but an empty string is allowed and
 * means the image is decorative — a missing value is a refusal
 * (`embed_alt_missing`). A video's alt text is optional in the lexicon, so it
 * is omitted when absent. At most four images and at most one video, never
 * both. `aspectRatio` is only emitted when both dimensions are present.
 */
final class AtprotoEmbed
{
	public const IMAGES = 'app.bsky.embed.images';

	public const VIDEO = 'app.bsky.embed.video';

	public const MAX_IMAGES = 4;

	public const MAX_VIDEOS = 1;

	public const MAX_IMAGE_BYTES = 1_000_000;

	public const MAX_VIDEO_BYTES = 50_000_000;

	public const MAX_ALT_LENGTH = 2000;

	public const MAX_DIMENSION = 10000;

	private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

	private const VIDEO_MIME_TYPES = ['video/mp4'];

	public function __construct(
		private readonly AtprotoBlobUploader $uploader,
	) {
	}

	public static function supportsMimeType(string $mimeType): bool
	{
		return in_array($mimeType, self::IMAGE_MIME_TYPES, true)
			|| in_array($mimeType, self::VIDEO_MIME_TYPES, true);
	}

	/**
	 * @param list<AtprotoMedia> $media
	 *
	 * @return array<string, mixed>|null `null` when there is nothing to embed
	 *
	 * @throws AtprotoBlobException  when a type, size or upload is refused
	 * @throws AtprotoEmbedException when the embed shape or alt/aspect rule fails
	 */
	public function build(array $media, AtprotoAccount $account): ?array
	{
		if ($media === []) {
			return null;
		}

		$images = [];
		$videos = [];
		foreach ($media as $item) {
			if ($item->isImage()) {
				$images[] = $item;
				continue;
			}

			if ($item->isVideo()) {
				$videos[] = $item;
				continue;
			}

			throw new AtprotoBlobException(
				AtprotoBlobException::MIME_UNSUPPORTED,
				'The media type ' . $item->mimeType . ' is not supported by AT Protocol.',
			);
		}

		if ($images !== [] && $videos !== []) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::MIXED_MEDIA,
				'A post cannot embed images and a video at the same time.',
			);
		}

		if (count($images) > self::MAX_IMAGES) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::TOO_MANY_IMAGES,
				'A post can embed at most ' . self::MAX_IMAGES . ' images.',
			);
		}

		if (count($videos) > self::MAX_VIDEOS) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::TOO_MANY_VIDEOS,
				'A post can embed at most one video.',
			);
		}

		if ($images !== []) {
			return $this->images($images, $account);
		}

		return $this->video($videos[0], $account);
	}

	/**
	 * @param list<AtprotoMedia> $images
	 *
	 * @return array<string, mixed>
	 */
	private function images(array $images, AtprotoAccount $account): array
	{
		$validated = [];
		foreach ($images as $image) {
			$this->assertSupportedImage($image);
			$this->assertAlt($image, true);
			$validated[] = $image;
		}

		$embedded = [];
		foreach ($validated as $image) {
			$entry = [
				'image' => $this->uploader->upload($account, $image)->toArray(),
				'alt' => (string) $image->alt,
			];

			$aspectRatio = $this->aspectRatio($image);
			if ($aspectRatio !== null) {
				$entry['aspectRatio'] = $aspectRatio;
			}

			$embedded[] = $entry;
		}

		return [
			'$type' => self::IMAGES,
			'images' => $embedded,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function video(AtprotoMedia $video, AtprotoAccount $account): array
	{
		$this->assertSupportedVideo($video);
		$this->assertAlt($video, false);
		$aspectRatio = $this->aspectRatio($video);

		$embed = [
			'$type' => self::VIDEO,
			'video' => $this->uploader->upload($account, $video)->toArray(),
		];

		if ($video->alt !== null) {
			$embed['alt'] = $video->alt;
		}

		if ($aspectRatio !== null) {
			$embed['aspectRatio'] = $aspectRatio;
		}

		return $embed;
	}

	private function assertSupportedImage(AtprotoMedia $image): void
	{
		if (!in_array($image->mimeType, self::IMAGE_MIME_TYPES, true)) {
			throw new AtprotoBlobException(
				AtprotoBlobException::MIME_UNSUPPORTED,
				'The image type ' . $image->mimeType . ' is not supported by AT Protocol.',
			);
		}

		if ($image->size() < 1) {
			throw new AtprotoBlobException(AtprotoBlobException::MISSING, 'An embedded image has no bytes.');
		}

		if ($image->size() > self::MAX_IMAGE_BYTES) {
			throw new AtprotoBlobException(
				AtprotoBlobException::TOO_LARGE,
				'An embedded image is larger than ' . self::MAX_IMAGE_BYTES . ' bytes.',
			);
		}
	}

	private function assertSupportedVideo(AtprotoMedia $video): void
	{
		if (!in_array($video->mimeType, self::VIDEO_MIME_TYPES, true)) {
			throw new AtprotoBlobException(
				AtprotoBlobException::MIME_UNSUPPORTED,
				'The video type ' . $video->mimeType . ' is not supported by AT Protocol.',
			);
		}

		if ($video->size() < 1) {
			throw new AtprotoBlobException(AtprotoBlobException::MISSING, 'The embedded video has no bytes.');
		}

		if ($video->size() > self::MAX_VIDEO_BYTES) {
			throw new AtprotoBlobException(
				AtprotoBlobException::TOO_LARGE,
				'The embedded video is larger than ' . self::MAX_VIDEO_BYTES . ' bytes.',
			);
		}
	}

	private function assertAlt(AtprotoMedia $media, bool $required): void
	{
		if ($media->alt === null) {
			if (!$required) {
				return;
			}

			throw new AtprotoEmbedException(
				AtprotoEmbedException::ALT_MISSING,
				'Every embedded image needs an alt text; an empty string is allowed.',
			);
		}

		if (mb_strlen($media->alt) > self::MAX_ALT_LENGTH) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::ALT_TOO_LONG,
				'An alt text is longer than ' . self::MAX_ALT_LENGTH . ' characters.',
			);
		}
	}

	/**
	 * @return array{width: int, height: int}|null
	 */
	private function aspectRatio(AtprotoMedia $media): ?array
	{
		if ($media->width === null && $media->height === null) {
			return null;
		}

		if ($media->width === null || $media->height === null) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::ASPECT_RATIO_INVALID,
				'An aspect ratio needs both a width and a height.',
			);
		}

		if ($media->width < 1
			|| $media->height < 1
			|| $media->width > self::MAX_DIMENSION
			|| $media->height > self::MAX_DIMENSION
		) {
			throw new AtprotoEmbedException(
				AtprotoEmbedException::ASPECT_RATIO_INVALID,
				'The aspect ratio dimensions are out of range.',
			);
		}

		return ['width' => $media->width, 'height' => $media->height];
	}
}
