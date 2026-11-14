<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileField;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LocalActorProfileTest extends TestCase
{
	public function testAProfileExposesOnlyBoundedPublicFields(): void
	{
		$profile = new LocalActorProfile(
			userId: 'alice',
			displayName: 'Alice',
			summary: 'Hello',
			avatarUrl: 'https://social.example/media/alice.png',
			headerUrl: 'https://social.example/media/header.png',
			attachmentUrls: ['https://social.example/media/one.png'],
			fields: [new LocalActorProfileField('Website', 'https://alice.example', true)],
			locked: true,
			hideCollections: true,
			updatedAt: Time::parse('2026-11-14T10:00:00Z'),
			createdAt: Time::parse('2026-01-01T00:00:00Z'),
		);

		self::assertSame('Alice', $profile->toArray()['display_name']);
		self::assertSame('https://social.example/media/alice.png', $profile->toArray()['avatar']);
		self::assertTrue($profile->toArray()['locked']);
		self::assertTrue($profile->toArray()['hide_collections']);
		self::assertTrue($profile->toArray()['fields'][0]['verified']);
	}

	public function testNonHttpUrlsAndTooManyFieldsAreRefused(): void
	{
		foreach ([
			'javascript:alert(1)',
			'/relative.png',
			'ftp://social.example/avatar.png',
			'https://social.example/' . str_repeat('a', LocalActorProfile::MAX_URL_LENGTH),
		] as $url) {
			try {
				new LocalActorProfile(
					'alice',
					'Alice',
					'',
					$url,
					null,
					[],
					[],
					false,
					false,
					Time::parse('2026-11-14T10:00:00Z'),
				);
				self::fail('The URL should have been refused: ' . $url);
			} catch (InvalidArgumentException) {
				self::addToAssertionCount(1);
			}
		}

		$this->expectException(InvalidArgumentException::class);
		new LocalActorProfile(
			'alice',
			'Alice',
			'',
			null,
			null,
			[],
			array_fill(0, LocalActorProfile::MAX_FIELDS + 1, new LocalActorProfileField('x', 'y')),
			false,
			false,
			Time::parse('2026-11-14T10:00:00Z'),
		);
	}

	public function testAProfileFieldNeedsBothParts(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new LocalActorProfileField('', 'value');
	}
}
