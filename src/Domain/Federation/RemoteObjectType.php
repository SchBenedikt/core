<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use function is_array;
use function is_string;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * The ActivityStreams object a remote post carries.
 *
 * Separate from {@see RemotePostKind}: a boost is still a boost whether it
 * announces a Note or an Event, and an Article is not a boost just because it
 * arrived with an empty body. Storing the type lets a client render the right
 * card instead of guessing from the payload.
 */
enum RemoteObjectType: string
{
	case Note = 'note';

	case Article = 'article';

	case Page = 'page';

	case Question = 'question';

	case Event = 'event';

	case Audio = 'audio';

	case Video = 'video';

	/**
	 * Maps an ActivityStreams `type` value, which may be a string or a list
	 * and may be a full IRI, onto the supported set.
	 */
	public static function fromActivityType(mixed $value): ?self
	{
		$candidates = is_array($value) ? $value : [$value];
		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || trim($candidate) === '') {
				continue;
			}

			$name = trim($candidate);
			$separator = strrpos($name, '#');
			if ($separator === false) {
				$separator = strrpos($name, '/');
			}

			if ($separator !== false) {
				$name = substr($name, $separator + 1);
			}

			$type = self::tryFrom(strtolower($name));
			if ($type !== null) {
				return $type;
			}
		}

		return null;
	}

	public function isMedia(): bool
	{
		return $this === self::Audio || $this === self::Video;
	}
}
