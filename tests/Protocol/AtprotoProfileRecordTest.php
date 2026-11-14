<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\AtprotoBlob;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\AtprotoProfile;
use AlohaSocial\Core\Protocol\Atproto\AtprotoProfileRecord;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoProfileRecordTest extends TestCase
{
	public function testTheRecordUsesTheSelfKeyAndOnlyTheGivenFields(): void
	{
		$profile = new AtprotoProfile(
			'Benedikt',
			'Building Aloha Social.',
			new AtprotoMedia('image/png', 'png-bytes'),
			new AtprotoMedia('image/jpeg', 'jpeg-bytes'),
		);

		$record = AtprotoProfileRecord::fromProfile(
			$profile,
			new AtprotoBlob('bafy-avatar', 'image/png', 9),
			new AtprotoBlob('bafy-banner', 'image/jpeg', 10),
			Time::parse('2026-11-11T10:00:00Z'),
		);

		self::assertSame('self', AtprotoProfileRecord::RKEY);
		self::assertSame('app.bsky.actor.profile', $record['$type']);
		self::assertSame('Benedikt', $record['displayName']);
		self::assertSame('Building Aloha Social.', $record['description']);
		self::assertSame('2026-11-11T10:00:00Z', $record['createdAt']);
		self::assertSame('bafy-avatar', $record['avatar']['ref']['$link']);
		self::assertSame('bafy-banner', $record['banner']['ref']['$link']);
	}

	public function testAnEmptyProfileWritesOnlyTheTypeAndTime(): void
	{
		$record = AtprotoProfileRecord::fromProfile(
			new AtprotoProfile(),
			null,
			null,
			Time::parse('2026-11-11T10:00:00Z'),
		);

		self::assertSame(['$type', 'createdAt'], array_keys($record));
	}

	public function testADisplayNameIsBounded(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoProfile(str_repeat('a', 65));
	}

	public function testAnAvatarMustBeAnImage(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoProfile(avatar: new AtprotoMedia('video/mp4', 'mp4'));
	}
}
