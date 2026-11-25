<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Events;

use AlohaSocial\Core\Domain\Post\Hashtags;

/** A client subscription after transport authentication and scope checks. */
final readonly class StreamSubscription
{
	/**
 * @param list<string> $channels Mastodon channel names, optionally with a validated suffix.
	 * @param list<string> $hashtags Normalized hashtag filters.
 * @param string|null $listId Opaque owner-scoped list id required by the `list` channel.
	 */
	public function __construct(
		public string $userId,
		public array $channels,
		public array $hashtags = [],
		public ?string $listId = null,
	) {
		if ($userId === '' || $channels === []) {
			throw new \InvalidArgumentException('A stream subscription requires an authenticated user and channel.');
		}
		if (count($channels) !== count(array_unique($channels))) {
			throw new \InvalidArgumentException('Stream channels must be unique.');
		}
		foreach ($channels as $channel) {
			if (!in_array($channel, ['user', 'user:notification', 'home', 'public', 'public:local', 'hashtag', 'list', 'direct'], true)) {
				throw new \InvalidArgumentException('Unsupported Mastodon stream channel.');
			}
		}
		if (in_array('list', $channels, true) !== ($listId !== null && $listId !== '')) {
			throw new \InvalidArgumentException('List stream subscriptions require exactly one list id.');
		}
		if (in_array('hashtag', $channels, true) !== ($hashtags !== [])) {
			throw new \InvalidArgumentException('Hashtag stream subscriptions require hashtag filters.');
		}
		foreach ($hashtags as $hashtag) {
			if (Hashtags::normalize($hashtag) !== $hashtag) {
				throw new \InvalidArgumentException('Hashtag stream filters must be normalized.');
			}
		}
	}
}
