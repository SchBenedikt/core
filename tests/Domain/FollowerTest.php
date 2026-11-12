<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FollowerTest extends TestCase
{
	public function testTheApiShapeHidesTheLocalUser(): void
	{
		$follower = $this->follower();

		self::assertSame(
			[
				'id' => 'f1',
				'actor' => 'https://remote.example/users/bob',
				'inbox' => 'https://remote.example/users/bob/inbox',
				'followed_at' => '2026-11-11T10:00:00Z',
			],
			$follower->toArray(),
		);
		self::assertArrayNotHasKey('local_user_id', $follower->toArray());
	}

	public function testWithInboxKeepsIdentityAndTime(): void
	{
		$updated = $this->follower()->withInbox('https://remote.example/inbox-v2');

		self::assertSame('f1', $updated->id);
		self::assertSame('https://remote.example/inbox-v2', $updated->inboxUrl);
		self::assertSame('2026-11-11T10:00:00Z', $updated->toArray()['followed_at']);
	}

	public function testASharedInboxIsPreferredForDelivery(): void
	{
		$follower = $this->follower()->withInbox(
			'https://remote.example/users/bob/inbox',
			'https://remote.example/inbox/shared',
		);

		self::assertSame('https://remote.example/inbox/shared', $follower->sharedInboxUrl);
		self::assertSame('https://remote.example/inbox/shared', $follower->deliveryInboxUrl());
	}

	public function testARepatedInboxUpdateKeepsTheKnownSharedInbox(): void
	{
		$follower = $this->follower()->withInbox(
			'https://remote.example/users/bob/inbox',
			'https://remote.example/inbox/shared',
		);

		$updated = $follower->withInbox('https://remote.example/users/bob/inbox-v2');

		self::assertSame('https://remote.example/inbox/shared', $updated->sharedInboxUrl);
		self::assertSame('https://remote.example/inbox/shared', $updated->deliveryInboxUrl());
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function incompleteFollowers(): iterable
	{
		yield 'empty id' => ['', 'alice', 'https://remote.example/users/bob', 'https://remote.example/inbox'];
		yield 'empty local user' => ['f1', '', 'https://remote.example/users/bob', 'https://remote.example/inbox'];
		yield 'empty actor' => ['f1', 'alice', '', 'https://remote.example/inbox'];
		yield 'empty inbox' => ['f1', 'alice', 'https://remote.example/users/bob', ''];
	}

	public function testAnEmptySharedInboxIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Follower(
			'f1',
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
			Time::parse('2026-11-11T10:00:00Z'),
			'',
		);
	}

	#[DataProvider('incompleteFollowers')]
	public function testAnIncompleteFollowerIsRefused(
		string $id,
		string $localUserId,
		string $actorId,
		string $inboxUrl,
	): void {
		$this->expectException(InvalidArgumentException::class);

		new Follower($id, $localUserId, $actorId, $inboxUrl, Time::parse('2026-11-11T10:00:00Z'));
	}

	private function follower(): Follower
	{
		return new Follower(
			'f1',
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
