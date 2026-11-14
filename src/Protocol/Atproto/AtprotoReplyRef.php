<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use InvalidArgumentException;

use function strlen;
use function trim;

/**
 * The `reply` object of an `app.bsky.feed.post` record.
 *
 * Both references need a `uri` and a `cid`; the AppView threads by CID and a
 * reference without one is rejected or shown as a broken reply. The root is
 * the thread's first post, so a reply to a reply inherits it instead of
 * pointing at its direct parent.
 */
final readonly class AtprotoReplyRef
{
	public function __construct(
		public string $rootUri,
		public string $rootCid,
		public string $parentUri,
		public string $parentCid,
	) {
		$this->assertReference($rootUri, $rootCid, 'root');
		$this->assertReference($parentUri, $parentCid, 'parent');
	}

	/**
	 * @return array{
	 *     root: array{uri: string, cid: string},
	 *     parent: array{uri: string, cid: string}
	 * }
	 */
	public function toArray(): array
	{
		return [
			'root' => ['uri' => $this->rootUri, 'cid' => $this->rootCid],
			'parent' => ['uri' => $this->parentUri, 'cid' => $this->parentCid],
		];
	}

	private function assertReference(string $uri, string $cid, string $kind): void
	{
		if (!str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol reply needs a valid ' . $kind . ' URI.');
		}

		if ($cid === '' || trim($cid) !== $cid || strlen($cid) > AtprotoRecord::MAX_CID_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol reply needs a ' . $kind . ' CID.');
		}
	}
}
