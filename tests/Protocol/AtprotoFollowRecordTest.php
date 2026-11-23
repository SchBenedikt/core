<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRecord;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoFollowRecordTest extends TestCase
{
	public function testBuildsAValidFollowRecordAndDeterministicRecordKey(): void
	{
		$time = Time::parse('2026-11-20T10:00:00Z');
		$record = AtprotoFollowRecord::create('did:plc:bob', $time);

		self::assertSame('app.bsky.graph.follow', $record['$type']);
		self::assertSame('did:plc:bob', $record['subject']);
		self::assertSame('2026-11-20T10:00:00Z', $record['createdAt']);
		self::assertSame(
			AtprotoFollowRecord::rkey($time, 'alice', 'did:plc:alice', 'did:plc:bob'),
			AtprotoFollowRecord::rkey(Time::parse('2026-11-21T11:00:00Z'), 'alice', 'did:plc:alice', 'did:plc:bob'),
		);
	}

	public function testRejectsAnInvalidSubjectDid(): void
	{
		$this->expectException(InvalidArgumentException::class);
		AtprotoFollowRecord::create('alice', Time::parse('2026-11-20T10:00:00Z'));
	}
}
