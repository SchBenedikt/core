<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The typed metadata of a remote object, independent of its body.
 *
 * A title is plain text: the peer's HTML is stripped before it is stored so a
 * card never becomes an injection point. The event and media parts are
 * optional and bounded by their value objects.
 */
final readonly class RemoteObjectMetadata
{
	public const MAX_TITLE_LENGTH = 255;

	public function __construct(
		public ?string $title = null,
		public ?RemoteEventMetadata $event = null,
		public ?RemoteMediaMetadata $media = null,
	) {
		if ($title !== null) {
			if (trim($title) === '') {
				throw new InvalidArgumentException('A title cannot be blank.');
			}

			if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
				throw new InvalidArgumentException('The title is too long.');
			}
		}
	}

	public function isEmpty(): bool
	{
		return $this->title === null && $this->event === null && $this->media === null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'event' => $this->event?->toArray(),
			'media' => $this->media?->toArray(),
		];
	}

	public function toJson(): string
	{
		return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Rehydrates stored JSON leniently: a row written by an older version or a
	 * hand-edited value must not make the whole timeline unreadable.
	 */
	public static function fromStored(?string $json): ?self
	{
		if ($json === null || trim($json) === '') {
			return null;
		}

		try {
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($data)) {
			return null;
		}

		try {
			$title = isset($data['title']) && is_string($data['title']) ? $data['title'] : null;

			$event = null;
			if (is_array($data['event'] ?? null)) {
				$eventData = $data['event'];
				$startsAt = self::parseTime($eventData['starts_at'] ?? null);
				$endsAt = self::parseTime($eventData['ends_at'] ?? null);
				$event = new RemoteEventMetadata(
					name: is_string($eventData['name'] ?? null) ? $eventData['name'] : '',
					startsAt: $startsAt ?? new DateTimeImmutable('@0'),
					endsAt: $endsAt,
					location: isset($eventData['location']) && is_string($eventData['location']) ? $eventData['location'] : null,
					participationUrl: isset($eventData['participation_url']) && is_string($eventData['participation_url'])
						? $eventData['participation_url']
						: null,
					status: RemoteEventStatus::fromActivityValue($eventData['status'] ?? null),
				);
			}

			$media = null;
			if (is_array($data['media'] ?? null)) {
				$mediaData = $data['media'];
				$sources = [];
				if (is_array($mediaData['sources'] ?? null)) {
					foreach ($mediaData['sources'] as $label => $url) {
						if (is_string($label) && is_string($url)) {
							$sources[$label] = $url;
						}
					}
				}

				$media = new RemoteMediaMetadata(
					durationSeconds: isset($mediaData['duration_seconds']) && is_int($mediaData['duration_seconds'])
						? $mediaData['duration_seconds']
						: null,
					thumbnailUrl: isset($mediaData['thumbnail_url']) && is_string($mediaData['thumbnail_url'])
						? $mediaData['thumbnail_url']
						: null,
					sources: $sources,
				);
			}

			$metadata = new self($title, $event, $media);

			return $metadata->isEmpty() ? null : $metadata;
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	private static function parseTime(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}
}
