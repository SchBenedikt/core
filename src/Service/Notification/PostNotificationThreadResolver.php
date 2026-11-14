<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Notification;

use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Domain\Notification\NotificationThreadResolver;
use AlohaSocial\Core\Domain\Post\PostRepository;

use function trim;

/**
 * Walks a reply chain to the thread root, bounded and cycle-safe.
 *
 * Local posts and cached remote thread nodes are supported. A missing parent,
 * a cycle or a chain longer than the bound answers null: an unknown root must
 * not make a conversation mute apply to the wrong thread.
 */
final readonly class PostNotificationThreadResolver implements NotificationThreadResolver
{
	private const MAX_DEPTH = 64;

	public function __construct(
		private PostRepository $posts,
		private ?RemoteThreadRepository $remoteThreads = null,
	) {
	}

	public function rootFor(string $postId): ?string
	{
		$current = trim($postId);
		if ($current === '') {
			return null;
		}

		$visited = [];
		$depth = 0;
		while ($depth++ < self::MAX_DEPTH) {
			if (isset($visited[$current])) {
				return null;
			}
			$visited[$current] = true;

			$local = $this->posts->find($current);
			if ($local !== null) {
				if ($local->inReplyToId === null) {
					return $local->id;
				}

				if ($this->posts->find($local->inReplyToId) === null
					&& ($this->remoteThreads?->find($local->inReplyToId) === null)
				) {
					return null;
				}

				$current = $local->inReplyToId;
				continue;
			}

			$remote = $this->remoteThreads?->find($current);
			if ($remote === null) {
				return null;
			}

			if ($remote->inReplyToId === null) {
				return $remote->objectId;
			}

			$current = $remote->inReplyToId;
		}

		return null;
	}
}
