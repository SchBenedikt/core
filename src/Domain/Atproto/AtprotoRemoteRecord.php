<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * The latest known state of one remote repository record.
 *
 * A delete is a tombstone, not a missing row: a later create can be ordered
 * against it, and a delete that arrives before its create cannot be lost. The
 * record body is preserved for unsupported lexicons; only supported posts are
 * projected for reading.
 */
final readonly class AtprotoRemoteRecord
{
	/**
	 * @param array<string, mixed> $record
	 */
	public function __construct(
		public string $source,
		public string $did,
		public string $collection,
		public string $rkey,
		public string $uri,
		public ?string $cid,
		public ?string $rev,
		public ?int $sequence,
		public array $record,
		public bool $deleted,
		public ?DateTimeImmutable $publishedAt,
		public DateTimeImmutable $receivedAt,
		public DateTimeImmutable $updatedAt,
	) {
		if ($source === '' || !AtprotoIdentifiers::isDid($did)
			|| !AtprotoIdentifiers::isCollection($collection)
			|| !AtprotoIdentifiers::isRkey($rkey)
		) {
			throw new InvalidArgumentException('A remote record needs valid source and repository identifiers.');
		}

		if (!AtprotoIdentifiers::isAtUri($uri) || $uri !== AtprotoIdentifiers::uri($did, $collection, $rkey)) {
			throw new InvalidArgumentException('A remote record URI must match its repository identifiers.');
		}

		if ($cid !== null && ($cid === '' || trim($cid) !== $cid || mb_strlen($cid) > AtprotoEvent::MAX_CID_LENGTH)) {
			throw new InvalidArgumentException('A remote record CID is invalid.');
		}

		if ($sequence !== null && $sequence < 0) {
			throw new InvalidArgumentException('A remote record sequence cannot be negative.');
		}

		if (!$deleted && $record === []) {
			throw new InvalidArgumentException('A live remote record needs a body.');
		}
	}

	public function isPost(): bool
	{
		return $this->collection === 'app.bsky.feed.post';
	}

	public function isLive(): bool
	{
		return !$this->deleted;
	}
}
