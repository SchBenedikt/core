<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\AttachmentPresenter;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Domain\Media\MediaMetadataStripper;
use AlohaSocial\Core\Domain\Media\NativeMediaProbe;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Image\ImageProcessingException;
use AlohaSocial\Core\Platform\Image\ImageProcessor;
use AlohaSocial\Core\Platform\Media\MediaProbe;
use AlohaSocial\Core\Platform\Media\MediaTranscoder;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;

use function in_array;
use function is_array;
use function max;
use function min;
use function strlen;
use function trim;

/**
 * Uploads and presents images.
 *
 * The bytes are validated and decoded before either blob is written. The
 * original is stored untouched; the thumbnail is a separate object. A failure
 * at any later step removes both blobs and leaves no metadata row, so a
 * client never gets a half-created attachment.
 */
final class MediaService implements AttachmentPresenter
{
	public const CONFIG_APP = 'social';

	public const CONFIG_MAX_BYTES = 'media.max_bytes';

	public const CONFIG_MAX_VIDEO_BYTES = 'media.max_video_bytes';

	public const CONFIG_MAX_AUDIO_BYTES = 'media.max_audio_bytes';

	public const CONFIG_THUMBNAIL_MAX_DIMENSION = 'media.thumbnail_max_dimension';

	public const DEFAULT_THUMBNAIL_MAX_DIMENSION = 640;

	public function __construct(
		private readonly AttachmentRepository $attachments,
		private readonly PostRepository $posts,
		private readonly BlobStore $blobs,
		private readonly ImageProcessor $images,
		private readonly Config $config,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?RemotePostRepository $remotePosts = null,
		private readonly ?MediaProbe $probe = null,
		private readonly ?MediaTranscoder $transcoder = null,
		private readonly ?MediaMetadataStripper $stripper = null,
	) {
	}

	/**
	 * @throws ValidationException when the upload is empty, too large, has an unsupported type or is not decodable
	 * @throws \AlohaSocial\Core\Platform\Storage\StorageException when a blob cannot be written or cleaned up
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the metadata row cannot be stored
	 */
	public function upload(
		string $ownerId,
		string $bytes,
		?string $altText = null,
		?string $declaredMimeType = null,
		?string $sourceUrl = null,
	): Attachment
	{
		$ownerId = trim($ownerId);
		if ($ownerId === '') {
			throw new ValidationException(['file' => 'An upload needs an owner.']);
		}

		$altText = $altText === null ? null : trim($altText);
		if ($altText === '') {
			$altText = null;
		}

		$sourceUrl = $sourceUrl === null ? null : trim($sourceUrl);
		if ($sourceUrl === '') {
			$sourceUrl = null;
		}

		if ($sourceUrl !== null && mb_strlen($sourceUrl) > Attachment::MAX_SOURCE_URL_LENGTH) {
			throw new ValidationException(['file' => 'The attachment source URL is too long.']);
		}

		$declaredMimeType = $this->normalizeDeclaredMimeType($declaredMimeType);

		if ($sourceUrl !== null) {
			$existing = $this->attachments->findBySourceUrl($sourceUrl);
			if ($existing !== null) {
				return $existing;
			}
		}

		if ($altText !== null && mb_strlen($altText) > Attachment::MAX_ALT_TEXT_LENGTH) {
			throw new ValidationException([
				'alt_text' => 'Alt text may be at most ' . Attachment::MAX_ALT_TEXT_LENGTH . ' characters long.',
			]);
		}

		if ($bytes === '') {
			throw new ValidationException(['file' => 'The uploaded file is empty.']);
		}

		$detected = ($this->probe ?? new NativeMediaProbe())->probe($bytes);
		if ($detected === null) {
			throw new ValidationException([
				'file' => 'The uploaded file is not a supported image, video or audio file.',
			]);
		}

		$mimeType = $detected->mimeType;
		$kind = $detected->kind;
		if (!in_array($mimeType, $kind->mimeTypes(), true)) {
			throw new ValidationException(['file' => 'The uploaded file has an unsupported type.']);
		}

		if ($declaredMimeType !== null && $declaredMimeType !== $mimeType) {
			throw new ValidationException(['file' => 'The declared media type does not match the uploaded file.']);
		}

		if (strlen($bytes) > $this->maxBytes($kind)) {
			throw new ValidationException(['file' => 'The uploaded file is too large.']);
		}

		$width = $detected->width;
		$height = $detected->height;
		$duration = $detected->durationSeconds;
		if ($kind === MediaKind::Image
			&& ($width < 1 || $height < 1
				|| $width > Attachment::MAX_DIMENSION
				|| $height > Attachment::MAX_DIMENSION
				|| $width * $height > Attachment::MAX_PIXELS)
		) {
			throw new ValidationException(['file' => 'The image dimensions are not supported.']);
		}

		$thumbnail = null;
		if ($kind === MediaKind::Image) {
			$bytes = ($this->stripper ?? new MediaMetadataStripper())->strip($bytes, $mimeType);

			try {
				$thumbnail = $this->images->thumbnail(
					$bytes,
					$this->thumbnailMaxDimension(),
					$this->thumbnailMaxDimension(),
				);
			} catch (ImageProcessingException) {
				throw new ValidationException(['file' => 'The uploaded file is not a decodable image.']);
			}

			if (!in_array($thumbnail->mimeType, Attachment::IMAGE_MIME_TYPES, true)) {
				throw new ValidationException(['file' => 'The generated thumbnail has an unsupported type.']);
			}
		} elseif ($kind === MediaKind::Video && $this->transcoder !== null) {
			try {
				$transcoded = $this->transcoder->transcode($bytes, $mimeType);
			} catch (\Throwable) {
				throw new ValidationException(['file' => 'The video could not be transcoded.']);
			}

			if ($transcoded === null) {
				throw new ValidationException(['file' => 'The video could not be transcoded.']);
			}

			$verified = ($this->probe ?? new NativeMediaProbe())->probe($transcoded->bytes);
			if ($verified === null || $verified->kind !== MediaKind::Video) {
				throw new ValidationException(['file' => 'The transcoded video is not playable.']);
			}

			$bytes = $transcoded->bytes;
			$mimeType = $verified->mimeType;
			$width = $verified->width;
			$height = $verified->height;
			$duration = $verified->durationSeconds;
		}

		$id = $this->ids->generate();
		$extension = Attachment::EXTENSIONS[$mimeType];
		$storageKey = 'media/' . $id . '/original.' . $extension;
		$thumbnailKey = $thumbnail === null ? '' : 'media/' . $id . '/thumbnail.' . $extension;

		$attachment = new Attachment(
			id: $id,
			ownerId: $ownerId,
			storageKey: $storageKey,
			thumbnailKey: $thumbnailKey,
			mimeType: $mimeType,
			width: $width,
			height: $height,
			byteSize: strlen($bytes),
			altText: $altText,
			createdAt: Time::toSecondPrecision($this->clock->now()),
			sourceUrl: $sourceUrl,
			kind: $kind,
			durationSeconds: $duration,
			animated: $detected->animated,
		);

		try {
			$this->blobs->put($storageKey, $bytes);
			if ($thumbnail !== null) {
				$this->blobs->put($thumbnailKey, $thumbnail->bytes);
			}
		} catch (\Throwable $exception) {
			$this->cleanup($storageKey);
			$this->cleanup($thumbnailKey);

			throw $exception;
		}

		try {
			$this->attachments->insert($attachment);
		} catch (\Throwable $exception) {
			$this->cleanup($storageKey);
			$this->cleanup($thumbnailKey);

			if ($sourceUrl !== null) {
				$existing = $this->attachments->findBySourceUrl($sourceUrl);
				if ($existing !== null) {
					return $existing;
				}
			}

			throw $exception;
		}

		return $attachment;
	}

	public function find(string $id): ?Attachment
	{
		return $this->attachments->find($id);
	}

	/**
	 * Whether a post still links this attachment.
	 *
	 * This is deliberately the same repository rule used by post creation.
	 * Soft-deleted posts count, because their link rows survive the tombstone.
	 */
	public function isAttachedToPost(Attachment $attachment): bool
	{
		return $this->posts->isAttachmentLinked($attachment->id);
	}

	/**
	 * Changes only the description of an existing attachment.
	 *
	 * Callers that expose this operation must first check ownership and that
	 * the attachment is not linked to a post. The bytes and storage keys are
	 * never touched here.
	 *
	 * @throws ValidationException when the description is too long
	 */
	public function updateAltText(Attachment $attachment, ?string $altText): Attachment
	{
		$altText = $altText === null ? null : trim($altText);
		if ($altText === '') {
			$altText = null;
		}

		if ($altText !== null && mb_strlen($altText) > Attachment::MAX_ALT_TEXT_LENGTH) {
			throw new ValidationException([
				'alt_text' => 'Alt text may be at most ' . Attachment::MAX_ALT_TEXT_LENGTH . ' characters long.',
			]);
		}

		$updated = $attachment->withAltText($altText);
		$this->attachments->updateAltText($updated->id, $altText);

		return $updated;
	}

	/**
	 * Changes the focal point of an attachment. Both coordinates are given; a
	 * null pair clears it. Ownership and attachment state are the caller's
	 * responsibility, like {@see updateAltText()}.
	 */
	public function updateFocus(Attachment $attachment, ?float $focusX, ?float $focusY): Attachment
	{
		$updated = $attachment->withFocus($focusX, $focusY);
		$this->attachments->updateFocus($updated->id, $focusX, $focusY);

		return $updated;
	}

	public function findBySourceUrl(string $sourceUrl): ?Attachment
	{
		return $this->attachments->findBySourceUrl($sourceUrl);
	}

	/**
	 * Removes an attachment row and both local blobs. Used when a remote post
	 * cannot be linked after its media was imported.
	 */
	public function discard(Attachment $attachment): void
	{
		$this->attachments->delete($attachment->id);
		$this->cleanup($attachment->storageKey);
		$this->cleanup($attachment->thumbnailKey);
	}

	/**
	 * @throws \AlohaSocial\Core\Platform\Storage\StorageException when the blob is missing or unreadable
	 */
	public function read(Attachment $attachment, bool $thumbnail = false): string
	{
		// Video and audio have no generated preview; a caller that asks for
		// one gets the original rather than an empty path, and the presenter
		// never advertises a thumbnail URL that does not exist.
		$key = $thumbnail && $attachment->thumbnailKey !== ''
			? $attachment->thumbnailKey
			: $attachment->storageKey;

		return $this->blobs->read($key);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function present(Attachment $attachment): array
	{
		$data = $attachment->toArray();
		$data['url'] = $this->url($attachment);
		$data['thumbnail_url'] = $attachment->thumbnailKey === '' ? null : $this->url($attachment, true);

		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function presentPost(Post $post): array
	{
		$data = $post->toArray();
		$attachments = array_values($this->forIds($post->attachmentIds));
		if ($attachments !== []) {
			$data['attachments'] = array_map(fn (Attachment $attachment): array => $this->present($attachment), $attachments);
		}

		return $data;
	}

	/**
	 * The note attachments of a post, in link order.
	 *
	 * A public or unlisted post may reference its media from a federated
	 * document: the binary route serves it to anybody so a peer can fetch it,
	 * and the id is unguessable. A followers-only or direct post sends no
	 * attachment links, because a peer fetching its note has no identity on
	 * the media route: {@see MediaAccess} would answer 404 to that anonymous
	 * request, and advertising a dead URL (or opening the route to everyone)
	 * are both worse than omitting it. Authenticated remote fetch is a separate
	 * feature; it must not be simulated by publishing the blob URL.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentActivityAttachments(Post $post): array
	{
		if (!$post->visibility->isPubliclyVisible()) {
			return [];
		}

		$attachments = $this->forIds($post->attachmentIds);

		$presented = [];
		foreach ($attachments as $attachment) {
			$entry = [
				'type' => match ($attachment->kind) {
					MediaKind::Image => 'Image',
					MediaKind::Video => 'Video',
					MediaKind::Audio => 'Audio',
				},
				'url' => $this->url($attachment),
				'mediaType' => $attachment->mimeType,
			];

			if ($attachment->width > 0 && $attachment->height > 0) {
				$entry['width'] = $attachment->width;
				$entry['height'] = $attachment->height;
			}

			if ($attachment->durationSeconds > 0) {
				$entry['duration'] = $this->duration($attachment->durationSeconds);
			}

			// An absent alternative text is not the same as an empty one; only
			// send the property when the author supplied a description.
			if ($attachment->altText !== null) {
				$entry['name'] = $attachment->altText;
			}

			$presented[] = $entry;
		}

		return $presented;
	}

	/**
	 * Alternative texts of several posts, keyed by post id.
	 *
	 * A keyword filter has to see the description as well as the body; doing
	 * it here reuses the one batched attachment query the presenter already
	 * needs instead of loading each attachment separately.
	 *
	 * @param list<Post> $posts
	 *
	 * @return array<string, list<string>>
	 */
	public function altTextsForPosts(array $posts): array
	{
		$ids = [];
		foreach ($posts as $post) {
			foreach ($post->attachmentIds as $attachmentId) {
				$ids[$attachmentId] = true;
			}
		}

		$attachments = $this->forIds(array_keys($ids));
		$result = [];
		foreach ($posts as $post) {
			$texts = [];
			foreach ($post->attachmentIds as $attachmentId) {
				$altText = $attachments[$attachmentId]->altText ?? null;
				if ($altText !== null && $altText !== '') {
					$texts[] = $altText;
				}
			}

			$result[$post->id] = $texts;
		}

		return $result;
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentPosts(array $posts): array
	{
		return array_map(fn (Post $post): array => $this->presentPost($post), $posts);
	}

	/**
	 * @param list<TimelineEntry> $entries
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentTimelineEntries(array $entries): array
	{
		$postIds = [];
		$remoteIds = [];
		foreach ($entries as $entry) {
			// A local entry has a visibility; a remote entry deliberately has
			// none and resolves its attachments through the federation link.
			if ($entry->visibility !== null) {
				$postIds[] = $entry->id;
			} else {
				$remoteIds[] = $entry->id;
			}
		}

		$idsByPost = $this->posts->attachmentIdsFor(array_values(array_unique($postIds)));
		$idsByRemotePost = $this->remotePosts === null
			? []
			: $this->remotePosts->attachmentIdsFor(array_values(array_unique($remoteIds)));
		$allIds = [];
		foreach (array_merge($idsByPost, $idsByRemotePost) as $ids) {
			foreach ($ids as $id) {
				$allIds[$id] = true;
			}
		}

		$attachments = $this->forIds(array_keys($allIds));

		$result = [];
		foreach ($entries as $entry) {
			$data = $entry->toArray();
			$ids = $entry->visibility === null
				? ($idsByRemotePost[$entry->id] ?? [])
				: ($idsByPost[$entry->id] ?? []);
			$presented = [];
			foreach ($ids as $id) {
				if (isset($attachments[$id])) {
					$presented[] = $this->present($attachments[$id]);
				}
			}

			if ($presented !== []) {
				$data['attachments'] = $presented;
			}

			$result[] = $data;
		}

		return $result;
	}

	/**
	 * @return array<string, Attachment> keyed by id, in the requested order
	 */
	private function forIds(array $ids): array
	{
		$found = $this->attachments->findMany($ids);
		$ordered = [];
		foreach ($ids as $id) {
			if (isset($found[$id])) {
				$ordered[$id] = $found[$id];
			}
		}

		return $ordered;
	}

	private function normalizeDeclaredMimeType(?string $declared): ?string
	{
		if ($declared === null) {
			return null;
		}

		$declared = strtolower(trim(explode(';', $declared, 2)[0]));
		if ($declared === '' || $declared === 'application/octet-stream') {
			return null;
		}

		$declared = match ($declared) {
			'image/jpg', 'image/pjpeg' => 'image/jpeg',
			'audio/mp3' => 'audio/mpeg',
			'audio/x-wav', 'audio/wave' => 'audio/wav',
			default => $declared,
		};

		if (!in_array($declared, Attachment::ALLOWED_MIME_TYPES, true)) {
			throw new ValidationException([
				'file' => 'Only JPEG, PNG, WebP and GIF images, MP4/WebM video and MP3/M4A/Ogg/WAV audio are supported.',
			]);
		}

		return $declared;
	}

	private function maxBytes(MediaKind $kind): int
	{
		$key = match ($kind) {
			MediaKind::Image => self::CONFIG_MAX_BYTES,
			MediaKind::Video => self::CONFIG_MAX_VIDEO_BYTES,
			MediaKind::Audio => self::CONFIG_MAX_AUDIO_BYTES,
		};

		$configured = $this->config->getInt(self::CONFIG_APP, $key, $kind->maxBytes());

		return max(1, min($kind->maxBytes(), $configured));
	}

	private function thumbnailMaxDimension(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_THUMBNAIL_MAX_DIMENSION,
			self::DEFAULT_THUMBNAIL_MAX_DIMENSION,
		);

		return max(1, min(2000, $configured));
	}

	/**
	 * An ActivityStreams duration is an ISO 8601 duration, not seconds.
	 */
	private function duration(int $seconds): string
	{
		$hours = intdiv($seconds, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		$rest = $seconds % 60;

		$value = 'PT';
		if ($hours > 0) {
			$value .= $hours . 'H';
		}
		if ($minutes > 0) {
			$value .= $minutes . 'M';
		}
		if ($rest > 0 || $value === 'PT') {
			$value .= $rest . 'S';
		}

		return $value;
	}

	private function url(Attachment $attachment, bool $thumbnail = false): string
	{
		$base = trim((string) $this->config->get(self::CONFIG_APP, 'instance.url', ''));
		$base = $base === '' ? '' : rtrim($base, '/');
		$path = '/api/v1/media/' . rawurlencode($attachment->id) . ($thumbnail ? '?variant=thumbnail' : '');

		return $base . $path;
	}

	private function cleanup(string $key): void
	{
		if ($key === '') {
			return;
		}

		try {
			$this->blobs->delete($key);
		} catch (\Throwable) {
			// A cleanup failure must not hide the original write/DB error.
		}
	}
}
