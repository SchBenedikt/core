<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class StoryActivityTest extends TestCase
{
	public function testAddCarriesAFollowersOnlyStoryObject(): void
	{
		$factory = new ActivityFactory(new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]));
		$story = new Story(
			's1',
			'alice',
			true,
			'https://social.example/stories/s1',
			'hello',
			Time::parse('2026-11-15T10:00:00Z'),
			Time::parse('2026-11-16T10:00:00Z'),
			'a1',
		);

		$activity = $factory->addStory($story, 'https://social.example/api/v1/stories/s1/media', 'image/jpeg');

		self::assertSame('Add', $activity['type']);
		self::assertSame('Story', $activity['object']['type']);
		self::assertSame(['https://social.example/@alice/followers'], $activity['to']);
		self::assertArrayNotHasKey('cc', $activity);
		self::assertSame('2026-11-16T10:00:00Z', $activity['object']['endTime']);
		self::assertSame('https://social.example/api/v1/stories/s1/media', $activity['object']['attachment'][0]['url']);
		self::assertSame('image/jpeg', $activity['object']['attachment'][0]['mediaType']);
	}

	public function testDeleteCarriesATombstoneForTheSameObject(): void
	{
		$factory = new ActivityFactory(new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]));
		$story = new Story(
			's1',
			'alice',
			true,
			'https://social.example/stories/s1',
			'',
			Time::parse('2026-11-15T10:00:00Z'),
			Time::parse('2026-11-16T10:00:00Z'),
			'a1',
		);

		$activity = $factory->deleteStory($story, Time::parse('2026-11-15T11:00:00Z'));

		self::assertSame('Delete', $activity['type']);
		self::assertSame('https://social.example/stories/s1', $activity['object']['id']);
		self::assertSame('Tombstone', $activity['object']['type']);
		self::assertSame(['https://social.example/@alice/followers'], $activity['to']);
	}
}
