<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Tests\Support\FakeAtprotoEventSource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApplicationAtprotoSyncTest extends TestCase
{
	public function testWithoutASourceTheInboundServiceIsAbsent(): void
	{
		$app = Application::inMemory();

		self::assertNull($app->atprotoSync);
	}

	public function testASourceWiresTheServiceAndTheSyncJob(): void
	{
		$source = new FakeAtprotoEventSource('relay', [new AtprotoEventPage([
			new AtprotoEvent(
				'relay',
				AtprotoEventKind::Record,
				'did:plc:alice',
				new DateTimeImmutable('2026-11-15T10:00:00Z'),
				operation: AtprotoRecordOperation::Create,
				collection: 'app.bsky.feed.post',
				rkey: 'a',
				cid: 'cid-a',
				sequence: 1,
				record: [
					'$type' => 'app.bsky.feed.post',
					'text' => 'queued import',
					'createdAt' => '2026-11-15T10:00:00Z',
				],
			),
		], null)]);

		$app = Application::inMemory(atprotoEventSource: $source);
		self::assertNotNull($app->atprotoSync);

		$app->queue->push(JobType::ATPROTO_SYNC, ['limit' => 10]);
		$app->worker->runOnce(10);

		$page = $app->atprotoSync->feed('did:plc:alice');
		self::assertSame('queued import', $page->posts[0]->text);
	}
}
