<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * The profile a host wants to write to `app.bsky.actor.profile`.
 *
 * This is caller input: the host owns its local profile and passes the fields
 * it wants on the PDS. A missing field is omitted from the record instead of
 * being cleared, and an empty string is a deliberate clear.
 */
final readonly class AtprotoProfile
{
	public const MAX_DISPLAY_NAME = 64;

	public const MAX_DESCRIPTION = 256;

	public function __construct(
		public ?string $displayName = null,
		public ?string $description = null,
		public ?AtprotoMedia $avatar = null,
		public ?AtprotoMedia $banner = null,
	) {
		if ($displayName !== null
			&& ($displayName === '' || trim($displayName) !== $displayName
				|| mb_strlen($displayName) > self::MAX_DISPLAY_NAME)
		) {
			throw new InvalidArgumentException('A display name must be 1 to 64 trimmed characters.');
		}

		if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION) {
			throw new InvalidArgumentException('A profile description is longer than 256 characters.');
		}

		if ($avatar !== null && !$avatar->isImage()) {
			throw new InvalidArgumentException('A profile avatar must be an image.');
		}

		if ($banner !== null && !$banner->isImage()) {
			throw new InvalidArgumentException('A profile banner must be an image.');
		}
	}

	public function isEmpty(): bool
	{
		return $this->displayName === null
			&& $this->description === null
			&& $this->avatar === null
			&& $this->banner === null;
	}
}
