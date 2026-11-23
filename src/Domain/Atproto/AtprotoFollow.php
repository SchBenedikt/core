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

/** Durable local projection of an outbound AT Protocol graph follow. */
final readonly class AtprotoFollow
{
	public const PENDING = 'pending';

	public const ACTIVE = 'active';

	public function __construct(
		public string $localUserId,
		public string $accountDid,
		public string $subjectDid,
		public string $rkey,
		public ?string $uri,
		public ?string $cid,
		public string $state,
		public DateTimeImmutable $followedAt,
	) {
		if ($localUserId === '' || !AtprotoIdentifiers::isDid($accountDid)
			|| !AtprotoIdentifiers::isDid($subjectDid) || !AtprotoIdentifiers::isRkey($rkey)
		) {
			throw new InvalidArgumentException('An AT Protocol follow needs valid account and record identifiers.');
		}

		if (!in_array($state, [self::PENDING, self::ACTIVE], true)) {
			throw new InvalidArgumentException('An AT Protocol follow state is invalid.');
		}

		if ($uri !== null && $uri !== AtprotoIdentifiers::uri($accountDid, AtprotoFollowRecord::COLLECTION, $rkey)) {
			throw new InvalidArgumentException('An AT Protocol follow URI must match its account and record key.');
		}

		if ($cid !== null && ($cid === '' || trim($cid) !== $cid || strlen($cid) > 256)) {
			throw new InvalidArgumentException('An AT Protocol follow CID is invalid.');
		}

		if ($state === self::ACTIVE && ($uri === null || $cid === null)) {
			throw new InvalidArgumentException('An active AT Protocol follow needs its PDS URI and CID.');
		}
	}

	/** @return array<string, string> */
	public function toArray(): array
	{
		return [
			'did' => $this->subjectDid,
			'uri' => $this->uri ?? '',
			'cid' => $this->cid ?? '',
			'rkey' => $this->rkey,
			'state' => $this->state,
			'followed_at' => Time::format($this->followedAt),
		];
	}
}
