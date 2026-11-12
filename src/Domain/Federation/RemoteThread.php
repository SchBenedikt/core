<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * A bounded view of a remote conversation.
 *
 * `ancestors` is ordered oldest/root first, directly before the target.
 * Replies are direct children only; the service never walks a reply of a
 * reply. The target is kept separately so a client can anchor the view even
 * when a parent or reply could not be fetched.
 */
final readonly class RemoteThread
{
	/**
	 * @param list<RemoteThreadPost> $ancestors
	 * @param list<RemoteThreadPost> $replies
	 */
	public function __construct(
		public RemoteThreadPost $post,
		public array $ancestors = [],
		public array $replies = [],
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'post' => $this->post->toArray(),
			'ancestors' => array_map(
				static fn (RemoteThreadPost $post): array => $post->toArray(),
				$this->ancestors,
			),
			'replies' => array_map(
				static fn (RemoteThreadPost $post): array => $post->toArray(),
				$this->replies,
			),
		];
	}
}
