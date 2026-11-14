<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use InvalidArgumentException;

use function array_is_list;
use function count;
use function is_string;
use function mb_strlen;

/**
 * Playback metadata of a remote Video or Audio object.
 *
 * Media-only objects have no body, so this is what makes them renderable.
 * `sources` is keyed by the peer's media type (or a positional label) and is
 * ordered, so the client can pick the first playable format without guessing.
 */
final readonly class RemoteMediaMetadata
{
	public const MAX_SOURCES = 4;

	public const MAX_URL_LENGTH = 2048;

	public const MAX_DURATION_SECONDS = 2_592_000;

	public function __construct(
		public ?int $durationSeconds = null,
		public ?string $thumbnailUrl = null,
		/** @var array<string, string> */
		public array $sources = [],
	) {
		if ($durationSeconds !== null && ($durationSeconds < 0 || $durationSeconds > self::MAX_DURATION_SECONDS)) {
			throw new InvalidArgumentException('The media duration is out of range.');
		}

		if ($thumbnailUrl !== null && mb_strlen($thumbnailUrl) > self::MAX_URL_LENGTH) {
			throw new InvalidArgumentException('The thumbnail URL is too long.');
		}

		if (count($sources) > self::MAX_SOURCES) {
			throw new InvalidArgumentException('A media object has too many sources.');
		}

		if (array_is_list($sources) && $sources !== []) {
			throw new InvalidArgumentException('Media sources must be keyed by format.');
		}

		foreach ($sources as $label => $url) {
			if (!is_string($label) || $label === '' || !is_string($url) || $url === '') {
				throw new InvalidArgumentException('A media source needs a label and a URL.');
			}

			if (mb_strlen($url) > self::MAX_URL_LENGTH) {
				throw new InvalidArgumentException('A media source URL is too long.');
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'duration_seconds' => $this->durationSeconds,
			'thumbnail_url' => $this->thumbnailUrl,
			'sources' => $this->sources,
		];
	}
}
