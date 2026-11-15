<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AllowAllAtprotoContent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundSchema;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncException;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncService;
use AlohaSocial\Core\Tests\Support\CheckpointFailingAtprotoRepository;
use AlohaSocial\Core\Tests\Support\FakeAtprotoEventSource;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class AtprotoSyncTransactionTest extends TestCase
{
	public function testACheckpointFailureRollsBackTheRecordsOfThePage(): void
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoInboundSchema::create($database);
		$records = new PdoAtprotoInboundRepository($database);
		$source = new FakeAtprotoEventSource('relay', [new AtprotoEventPage([
			$this->event(),
		], null)]);

		$service = new AtprotoSyncService(
			$source,
			new CheckpointFailingAtprotoRepository($records),
			new AllowAllAtprotoContent(),
			new FrozenClock(new DateTimeImmutable('2026-11-15T12:00:00Z')),
			$database,
		);

		try {
			$service->sync();
			self::fail('The checkpoint failure must surface.');
		} catch (AtprotoSyncException $exception) {
			self::assertSame(AtprotoSyncException::STORE_FAILED, $exception->reason);
		}

		self::assertNull($records->record('relay', $this->event()->uri()));
		self::assertNull($records->checkpoint('relay'));
	}

	private function event(): AtprotoEvent
	{
		return new AtprotoEvent(
			'relay',
			AtprotoEventKind::Record,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			operation: AtprotoRecordOperation::Create,
			collection: 'app.bsky.feed.post',
			rkey: 'a',
			cid: 'cid-a',
			rev: '3ka',
			sequence: 1,
			record: [
				'$type' => 'app.bsky.feed.post',
				'text' => 'rolled back',
				'createdAt' => '2026-11-15T10:00:00Z',
			],
		);
	}
}
