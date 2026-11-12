<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\RemoteAttachment;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\ValidationException;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function mb_strlen;
use function is_array;
use function is_string;
use function mb_substr;
use function parse_url;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Imports images advertised by an incoming remote object.
 *
 * The post is already stored when this runs. Every attachment is an
 * independent best-effort unit: one bad URL, response or image must not remove
 * the post or the attachments that worked. A local attachment is reused by its
 * remote source URL, which makes repeated deliveries idempotent.
 */
final class RemoteMediaService
{
	public const MAX_ATTACHMENTS = 4;

	private const MAX_INSPECTED_ATTACHMENTS = 64;

	public function __construct(
		private readonly RemoteMediaFetcher $fetcher,
		private readonly MediaService $media,
		private readonly RemotePostRepository $remotePosts,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param mixed $object the embedded ActivityPub object; anything else is ignored
	 */
	public function import(RemotePost $post, mixed $object): void
	{
		if (!$this->fetcher->enabled()) {
			return;
		}

		$candidates = $this->candidates($post, $object);
		if ($candidates === []) {
			return;
		}

		$linkedIds = [];
		$created = [];

		foreach ($candidates as $candidate) {
			try {
				$existing = $this->media->findBySourceUrl($candidate->url);
				if ($existing !== null) {
					$linkedIds[] = $existing->id;

					continue;
				}

				$result = $this->fetcher->fetch($candidate->url);
				$attachment = $this->media->upload(
					$post->actorId,
					$result->body,
					$candidate->altText,
					$candidate->declaredMimeType ?? $result->contentType,
					$candidate->url,
				);

				$linkedIds[] = $attachment->id;
				$created[] = $attachment;
			} catch (RemoteMediaException $exception) {
				$this->skip($post, $candidate->url, $exception->reason, $exception->getMessage());
			} catch (ValidationException $exception) {
				$this->skip($post, $candidate->url, 'remote_media_invalid_image', $exception->getMessage());
			} catch (\Throwable $exception) {
				$this->skip($post, $candidate->url, 'remote_media_import_failed', $exception->getMessage());
			}
		}

		if ($linkedIds === []) {
			// A post with no importable media is still a valid post. Do not
			// replace links that an earlier successful import created.
			return;
		}

		try {
			$this->remotePosts->linkAttachments($post->activityId, array_values(array_unique($linkedIds)));
		} catch (\Throwable $exception) {
			$this->logger->error('Remote post attachments could not be linked.', [
				'activity_id' => $post->activityId,
				'reason' => 'remote_media_link_failed',
				'error' => $exception->getMessage(),
			]);

			// Only media created by this attempt is removed. An already
			// existing local copy is kept for the next delivery to reuse.
			foreach ($created as $attachment) {
				try {
					$this->media->discard($attachment);
				} catch (\Throwable $cleanupException) {
					$this->logger->error('A remote attachment could not be cleaned up after a link failure.', [
						'activity_id' => $post->activityId,
						'attachment_id' => $attachment->id,
						'reason' => 'remote_media_cleanup_failed',
						'error' => $cleanupException->getMessage(),
					]);
				}
			}
		}
	}

	/**
	 * @return list<RemoteAttachment>
	 */
	private function candidates(RemotePost $post, mixed $object): array
	{
		if (!is_array($object) || !array_key_exists('attachments', $object)) {
			return [];
		}

		$rawAttachments = $object['attachments'];
		if (!is_array($rawAttachments)) {
			$this->skip($post, null, 'remote_media_invalid_metadata', 'The attachments field is not a list.');

			return [];
		}

		$candidates = [];
		$seen = [];
		$inspected = 0;

		foreach ($rawAttachments as $raw) {
			++$inspected;
			if ($inspected > self::MAX_INSPECTED_ATTACHMENTS) {
				$this->skip($post, null, 'remote_media_attachment_limit', 'Too many attachment entries were advertised.');
				break;
			}

			$candidate = $this->candidate($post, $raw);
			if ($candidate === null) {
				continue;
			}

			if (isset($seen[$candidate->url])) {
				continue;
			}

			if (count($candidates) >= self::MAX_ATTACHMENTS) {
				$this->skip(
					$post,
					$candidate->url,
					'remote_media_attachment_limit',
					'Only ' . self::MAX_ATTACHMENTS . ' attachments are imported per post.',
				);
				break;
			}

			$seen[$candidate->url] = true;
			$candidates[] = $candidate;
		}

		return $candidates;
	}

	private function candidate(RemotePost $post, mixed $raw): ?RemoteAttachment
	{
		if (is_string($raw)) {
			$url = trim($raw);

			return $this->candidateFromUrl($post, $url, null, null);
		}

		if (!is_array($raw)) {
			$this->skip($post, null, 'remote_media_invalid_metadata', 'An attachment entry is not an object.');

			return null;
		}

		$type = $raw['type'] ?? null;
		if (is_string($type)) {
			$type = strtolower(trim($type));
			if (str_contains($type, '#')) {
				$type = substr($type, strrpos($type, '#') + 1);
			} elseif (str_contains($type, '/')) {
				$type = substr($type, (int) strrpos($type, '/') + 1);
			}

			if (!in_array($type, ['image', 'document'], true)) {
				$this->skip($post, null, 'remote_media_unsupported_type', 'Only image attachments are imported.');

				return null;
			}
		} elseif ($type !== null) {
			$this->skip($post, null, 'remote_media_invalid_metadata', 'The attachment type is not a string.');

			return null;
		}

		$url = $this->firstUrl($raw['url'] ?? null);
		if ($url === null) {
			$this->skip($post, null, 'remote_media_invalid_url', 'The attachment has no usable URL.');

			return null;
		}

		$declared = null;
		foreach (['mediaType', 'mimeType'] as $key) {
			$value = $raw[$key] ?? null;
			if ($value === null) {
				continue;
			}

			if (!is_string($value)) {
				$this->skip($post, $url, 'remote_media_invalid_metadata', 'The declared media type is not a string.');

				return null;
			}

			$declared = strtolower(trim(explode(';', $value, 2)[0]));
			break;
		}

		if ($declared !== null && !in_array($declared, Attachment::ALLOWED_MIME_TYPES, true)) {
			$this->skip($post, $url, 'remote_media_unsupported_type', 'The declared media type is not a supported image type.');

			return null;
		}

		if ($declared === null && isset($type) && $type === 'document') {
			$this->skip($post, $url, 'remote_media_unsupported_type', 'A document without an image media type is not imported.');

			return null;
		}

		$altText = null;
		foreach (['name', 'summary'] as $key) {
			$value = $raw[$key] ?? null;
			if (is_string($value) && trim($value) !== '') {
				$altText = trim($value);
				break;
			}
		}

		if ($altText !== null) {
			$altText = mb_substr($altText, 0, Attachment::MAX_ALT_TEXT_LENGTH);
		}

		return $this->candidateFromUrl($post, $url, $declared, $altText);
	}

	private function candidateFromUrl(
		RemotePost $post,
		string $url,
		?string $declared,
		?string $altText,
	): ?RemoteAttachment {
		if ($url === '' || mb_strlen($url) > Attachment::MAX_SOURCE_URL_LENGTH) {
			$this->skip($post, null, 'remote_media_invalid_url', 'The attachment URL is empty or too long.');

			return null;
		}

		$parts = parse_url($url);
		if (!is_array($parts)
			|| !isset($parts['scheme'], $parts['host'])
			|| !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| trim((string) $parts['host']) === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
		) {
			$this->skip($post, $url, 'remote_media_invalid_url', 'Only absolute http(s) media URLs are imported.');

			return null;
		}

		return new RemoteAttachment($url, $declared, $altText);
	}

	private function firstUrl(mixed $value): ?string
	{
		if (is_string($value)) {
			$value = trim($value);

			return $value === '' ? null : $value;
		}

		if (!is_array($value)) {
			return null;
		}

		if (isset($value['href']) && is_string($value['href'])) {
			$value = trim($value['href']);

			return $value === '' ? null : $value;
		}

		foreach ($value as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				return trim($entry);
			}

			if (is_array($entry) && isset($entry['href']) && is_string($entry['href']) && trim($entry['href']) !== '') {
				return trim($entry['href']);
			}
		}

		return null;
	}

	private function skip(RemotePost $post, ?string $url, string $reason, string $message): void
	{
		$this->logger->warning('Skipping remote media attachment.', [
			'activity_id' => $post->activityId,
			'reason' => $reason,
			'url' => $url,
			'error' => $message,
		]);
	}
}
