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

/** A successfully published AT Protocol like or repost for a local action. */
final readonly class AtprotoInteractionRecord
{
	public const LIKE = 'like';

	public const REPOST = 'repost';

	public function __construct(
		public string $localUserId,
		public string $accountDid,
		public string $postId,
		public string $kind,
		public string $rkey,
		public string $uri,
		public DateTimeImmutable $createdAt,
	) {
		if ($localUserId === '' || $accountDid === '' || $postId === '' || !in_array($kind, [self::LIKE, self::REPOST], true)) {
			throw new InvalidArgumentException('An AT Protocol interaction needs its account, post and kind.');
		}
		if ($rkey === '' || strlen($rkey) > 512 || !str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol interaction needs a record key and AT URI.');
		}
	}

	/** @return array<string, string> */
	public function toArray(): array
	{
		return [
			'local_user_id' => $this->localUserId,
			'account_did' => $this->accountDid,
			'post_id' => $this->postId,
			'kind' => $this->kind,
			'rkey' => $this->rkey,
			'uri' => $this->uri,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
