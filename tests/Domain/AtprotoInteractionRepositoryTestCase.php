<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class AtprotoInteractionRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AtprotoInteractionRepository;

	public function testInsertFindListAndRemoveAreScopedByIdentity(): void
	{
		$repository = $this->repository();
		$alice = $this->record('alice', 'did:plc:alice', 'post-a', 'like', 'rkey-a');
		$otherDid = $this->record('alice', 'did:plc:alice2', 'post-a', 'like', 'rkey-a2');
		$bob = $this->record('bob', 'did:plc:bob', 'post-a', 'repost', 'rkey-b');
		$repository->insert($alice);
		$repository->insert($otherDid);
		$repository->insert($bob);

		self::assertSame($alice->uri, $repository->find('alice', 'did:plc:alice', 'post-a', 'like')?->uri);
		self::assertCount(2, $repository->forUser('alice'));
		self::assertCount(1, $repository->forUser('bob'));
		self::assertTrue($repository->remove('alice', 'did:plc:alice', 'post-a', 'like'));
		self::assertFalse($repository->remove('alice', 'did:plc:alice', 'post-a', 'like'));
		self::assertNull($repository->find('alice', 'did:plc:alice', 'post-a', 'like'));
		self::assertNotNull($repository->find('alice', 'did:plc:alice2', 'post-a', 'like'));
	}

	public function testRecordKeyIsUniqueWithinOneAtprotoIdentity(): void
	{
		$repository = $this->repository();
		$repository->insert($this->record('alice', 'did:plc:alice', 'post-a', 'like', 'same-key'));
		$repository->insert($this->record('bob', 'did:plc:bob', 'post-a', 'like', 'same-key'));

		$this->expectException(DatabaseException::class);
		$repository->insert($this->record('alice', 'did:plc:alice', 'post-b', 'repost', 'same-key'));
	}

	private function record(string $user, string $did, string $post, string $kind, string $rkey): AtprotoInteractionRecord
	{
		return new AtprotoInteractionRecord(
			$user,
			$did,
			$post,
			$kind,
			$rkey,
			'at://' . $did . '/app.bsky.feed.' . ($kind === 'like' ? 'like' : 'repost') . '/' . $rkey,
			Time::parse('2026-11-24T00:00:00Z'),
		);
	}
}
