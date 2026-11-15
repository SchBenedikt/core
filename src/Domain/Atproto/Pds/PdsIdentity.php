<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;

final readonly class PdsIdentity
{
	public function __construct(
		public string $localUserId,
		public string $did,
		public string $handle,
		public string $signingKeyId,
		public string $rotationKeyId,
		public ?Cid $head,
		public ?string $headRev,
		public int $seq,
		public string $createdAt,
		public string $updatedAt,
	) {
		if ($localUserId === '' || $did === '' || $handle === '') {
			throw new \InvalidArgumentException('A PDS identity needs a local user, DID and handle.');
		}

		if ($seq < 0) {
			throw new \InvalidArgumentException('A PDS identity sequence cannot be negative.');
		}
	}

	public function withHead(Cid $head, string $rev, int $seq, string $updatedAt): self
	{
		return new self(
			$this->localUserId,
			$this->did,
			$this->handle,
			$this->signingKeyId,
			$this->rotationKeyId,
			$head,
			$rev,
			$seq,
			$this->createdAt,
			$updatedAt,
		);
	}

	public function withKeys(string $signingKeyId, string $rotationKeyId, string $updatedAt): self
	{
		return new self(
			$this->localUserId,
			$this->did,
			$this->handle,
			$signingKeyId,
			$rotationKeyId,
			$this->head,
			$this->headRev,
			$this->seq,
			$this->createdAt,
			$updatedAt,
		);
	}
}
