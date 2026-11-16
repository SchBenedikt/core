<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;

/**
 * One persisted firehose frame.
 *
 * `seq` is assigned by the repository inside the same transaction as the
 * repository write. The event keeps the CAR bytes and the operations, so a
 * subscriber can replay a frame without reading the current repository head
 * (which may already be newer).
 */
final readonly class PdsEvent
{
	/**
	 * @param list<array{action: string, path: string, cid: string}> $ops
	 * @param list<string> $blobs
	 */
	public function __construct(
		public int $seq,
		public string $did,
		public string $commitCid,
		public string $rev,
		public string $sinceRev,
		public array $ops,
		public string $blocks,
		public array $blobs,
		public string $createdAt,
	) {
		if ($seq < 0) {
			throw new \InvalidArgumentException('A firehose sequence cannot be negative.');
		}

		if ($did === '' || $commitCid === '' || $rev === '') {
			throw new \InvalidArgumentException('A firehose event needs a DID, commit and rev.');
		}
	}

	public function withSeq(int $seq): self
	{
		return new self(
			$seq,
			$this->did,
			$this->commitCid,
			$this->rev,
			$this->sinceRev,
			$this->ops,
			$this->blocks,
			$this->blobs,
			$this->createdAt,
		);
	}

	/**
	 * The DAG-CBOR event shape sent by com.atproto.sync.subscribeRepos.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$ops = [];
		foreach ($this->ops as $op) {
			$entry = [
				'action' => $op['action'],
				'path' => $op['path'],
			];
			if (isset($op['cid'])) {
				$entry['cid'] = Cid::fromString($op['cid']);
			}
			$ops[] = $entry;
		}

		return [
			'$type' => '#commit',
			'seq' => $this->seq,
			'rebase' => false,
			'tooBig' => false,
			'repo' => $this->did,
			'commit' => Cid::fromString($this->commitCid),
			'rev' => $this->rev,
			'since' => $this->sinceRev,
			'blocks' => new Bytes($this->blocks),
			'ops' => $ops,
			'blobs' => array_map(static fn (string $cid): Cid => Cid::fromBlobString($cid), $this->blobs),
			'time' => $this->createdAt,
		];
	}
}
