<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoSyncCheckpoint;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class AtprotoInboundRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AtprotoInboundRepository;

	public function testCheckpointRoundTripsWithCursorAndStaleState(): void
	{
		$repository = $this->repository();
		$checkpoint = AtprotoSyncCheckpoint::initial('relay', $this->at('2026-11-15T10:00:00Z'))
			->advanced('cursor-1', 42, $this->at('2026-11-15T10:01:00Z'), $this->at('2026-11-15T10:02:00Z'), true);

		$repository->saveCheckpoint($checkpoint);
		$stored = $repository->checkpoint('relay');

		self::assertNotNull($stored);
		self::assertSame('cursor-1', $stored->cursor);
		self::assertSame(42, $stored->lastSequence);
		self::assertTrue($stored->reconcileRequired);
		self::assertFalse($stored->stale);

		$repository->saveCheckpoint($stored->failed($this->at('2026-11-15T10:03:00Z'), 'relay down'));
		$failed = $repository->checkpoint('relay');
		self::assertTrue($failed?->stale);
		self::assertSame('relay down', $failed?->lastError);
	}

	public function testRecordRoundTripsAndTombstonesAreNotLive(): void
	{
		$repository = $this->repository();
		$record = $this->record('abc', 'cid-1', '2026-11-15T10:00:00Z');
		$repository->saveRecord($record);

		$stored = $repository->record('relay', $record->uri);
		self::assertSame('cid-1', $stored?->cid);
		self::assertTrue($stored?->isLive());
		self::assertSame('hello', $stored?->record['text']);

		$tombstone = new AtprotoRemoteRecord(
			'relay',
			$record->did,
			$record->collection,
			$record->rkey,
			$record->uri,
			null,
			'3kabc',
			2,
			[],
			true,
			null,
			$this->at('2026-11-15T10:01:00Z'),
			$this->at('2026-11-15T10:01:00Z'),
		);
		$repository->saveRecord($tombstone);

		self::assertTrue($repository->record('relay', $record->uri)?->deleted);
		self::assertSame([], $repository->listRecords('relay', $record->did, $record->collection, 10));
	}

	public function testTheTimelineIsNewestFirstAndUsesAnOpaqueTupleCursor(): void
	{
		$repository = $this->repository();
		$repository->saveRecord($this->record('a', 'cid-a', '2026-11-15T10:00:00Z'));
		$repository->saveRecord($this->record('b', 'cid-b', '2026-11-15T11:00:00Z'));
		$repository->saveRecord($this->record('c', 'cid-c', '2026-11-15T12:00:00Z'));

		$first = $repository->listRecords('relay', 'did:plc:alice', 'app.bsky.feed.post', 2);
		self::assertCount(2, $first);
		self::assertSame('c', $first[0]->rkey);
		self::assertSame('b', $first[1]->rkey);

		$next = $repository->listRecords(
			'relay',
			'did:plc:alice',
			'app.bsky.feed.post',
			2,
			$first[1]->publishedAt,
			$first[1]->uri,
		);
		self::assertCount(1, $next);
		self::assertSame('a', $next[0]->rkey);
	}

	public function testDeletingAnAccountRemovesAllOfItsRecords(): void
	{
		$repository = $this->repository();
		$repository->saveRecord($this->record('a', 'cid-a', '2026-11-15T10:00:00Z'));
		$repository->saveRecord($this->record('b', 'cid-b', '2026-11-15T11:00:00Z'));

		self::assertSame(2, $repository->deleteRecords('relay', 'did:plc:alice'));
		self::assertSame(0, $repository->deleteRecords('relay', 'did:plc:alice'));
		self::assertSame([], $repository->listRecords('relay', 'did:plc:alice', 'app.bsky.feed.post', 10));
	}

	public function testAccountStateAndHandleLookupRoundTrip(): void
	{
		$repository = $this->repository();
		$account = new AtprotoRemoteAccount(
			'relay',
			'did:plc:alice',
			'alice.test',
			AtprotoAccountStatus::Active,
			true,
			$this->at('2026-11-15T10:00:00Z'),
		);
		$repository->saveAccount($account);

		self::assertSame('alice.test', $repository->account('relay', 'did:plc:alice')?->handle);
		self::assertSame('did:plc:alice', $repository->accountByHandle('relay', 'alice.test')?->did);
		self::assertNull($repository->account('other', 'did:plc:alice'));
	}

	protected function record(string $rkey, string $cid, string $publishedAt): AtprotoRemoteRecord
	{
		$did = 'did:plc:alice';

		return new AtprotoRemoteRecord(
			'relay',
			$did,
			'app.bsky.feed.post',
			$rkey,
			'at://' . $did . '/app.bsky.feed.post/' . $rkey,
			$cid,
			'3k' . $rkey,
			1,
			['$type' => 'app.bsky.feed.post', 'text' => 'hello', 'createdAt' => $publishedAt],
			false,
			$this->at($publishedAt),
			$this->at($publishedAt),
			$this->at($publishedAt),
		);
	}

	protected function at(string $value): \DateTimeImmutable
	{
		return Time::parse($value);
	}
}
