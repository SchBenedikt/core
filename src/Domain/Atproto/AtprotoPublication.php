<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The record one local post became on a remote PDS.
 *
 * The row is written only after the PDS accepted the record. Its uniqueness
 * by (local user, post) is what lets a retried job return the same result
 * instead of publishing a second record.
 */
final readonly class AtprotoPublication
{
	public function __construct(
		public string $localUserId,
		public string $postId,
		public string $rkey,
		public string $uri,
		public DateTimeImmutable $publishedAt,
	) {
		if ($localUserId === '' || $postId === '' || $rkey === '') {
			throw new InvalidArgumentException('An AT Protocol publication needs an account, a post and a record key.');
		}

		if (!str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol publication needs a valid AT URI.');
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return [
			'post_id' => $this->postId,
			'rkey' => $this->rkey,
			'uri' => $this->uri,
			'published_at' => Time::format($this->publishedAt),
		];
	}
}
