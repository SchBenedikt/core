<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\AtprotoInteractionRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoStrongRef;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoInteractionRecordTest extends TestCase
{
	private const SUBJECT = 'at://did:plc:alice/app.bsky.feed.post/abc123';

	public function testALikeCarriesTheSubjectAndTheCreationTime(): void
	{
		$record = AtprotoInteractionRecord::like(
			new AtprotoStrongRef(self::SUBJECT, 'bafy-subject'),
			Time::parse('2026-11-11T10:00:00Z'),
		);

		self::assertSame([
			'$type' => 'app.bsky.feed.like',
			'subject' => ['uri' => self::SUBJECT, 'cid' => 'bafy-subject'],
			'createdAt' => '2026-11-11T10:00:00Z',
		], $record);
	}

	public function testARepostUsesTheRepostCollection(): void
	{
		$record = AtprotoInteractionRecord::repost(
			new AtprotoStrongRef(self::SUBJECT, 'bafy-subject'),
			Time::parse('2026-11-11T10:00:00Z'),
		);

		self::assertSame('app.bsky.feed.repost', $record['$type']);
		self::assertSame(['uri' => self::SUBJECT, 'cid' => 'bafy-subject'], $record['subject']);
	}

	public function testAStrongReferenceNeedsAnAtUri(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoStrongRef('https://example.com/post', 'bafy-subject');
	}

	public function testAStrongReferenceNeedsACid(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoStrongRef(self::SUBJECT, ' ');
	}
}
