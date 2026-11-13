<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both mute repositories must satisfy.
 *
 * Mutes are private, so every read and delete is scoped to the owner. The
 * pair is an upsert, the list is newest-first with actor id as the tie-break,
 * and expiry hides a row without deleting it.
 */
abstract class MuteRepositoryTestCase extends TestCase
{
	abstract protected function repository(): MuteRepository;

	public function testSaveFindAndRemoveAreScopedToTheOwner(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute('alice', 'bob', true));

		self::assertSame('bob', $repository->find('alice', 'bob')?->actorId);
		self::assertTrue($repository->find('alice', 'bob')?->notifications);
		self::assertNull($repository->find('carol', 'bob'));
		self::assertNull($repository->find('alice', 'mallory'));

		self::assertFalse($repository->remove('carol', 'bob'));
		self::assertNotNull($repository->find('alice', 'bob'));

		self::assertTrue($repository->remove('alice', 'bob'));
		self::assertNull($repository->find('alice', 'bob'));
		self::assertFalse($repository->remove('alice', 'bob'));
	}

	public function testSavingTheSamePairUpdatesTheSettingsAndStartTime(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute('alice', 'bob', false, '2026-11-11T10:00:00Z'));
		$repository->save($this->mute(
			'alice',
			'bob',
			true,
			'2026-11-11T11:00:00Z',
			'2026-11-12T11:00:00Z',
		));

		$mute = $repository->find('alice', 'bob');

		self::assertNotNull($mute);
		self::assertTrue($mute->notifications);
		self::assertSame('2026-11-11T11:00:00Z', $mute->createdAt->format('Y-m-d\TH:i:s\Z'));
		self::assertSame('2026-11-12T11:00:00Z', $mute->expiresAt?->format('Y-m-d\TH:i:s\Z'));
		self::assertCount(1, $repository->forUser('alice', 10));
	}

	public function testMutesAreNewestFirstWithActorIdAsTieBreak(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute('alice', 'https://a.example/users/one', false, '2026-11-11T10:00:00Z'));
		$repository->save($this->mute('alice', 'https://c.example/users/three', false, '2026-11-11T10:02:00Z'));
		$repository->save($this->mute('alice', 'https://b.example/users/two', false, '2026-11-11T10:02:00Z'));
		$repository->save($this->mute('carol', 'https://d.example/users/four', false, '2026-11-11T10:03:00Z'));

		self::assertSame(
			[
				'https://c.example/users/three',
				'https://b.example/users/two',
				'https://a.example/users/one',
			],
			array_map(
				static fn (Mute $mute): string => $mute->actorId,
				$repository->forUser('alice', 10),
			),
		);
	}

	public function testBeforeAndAfterAreExclusiveCursors(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute('alice', 'old', false, '2026-11-11T10:00:00Z'));
		$repository->save($this->mute('alice', 'new', false, '2026-11-11T10:05:00Z'));

		self::assertSame(
			['old'],
			array_map(
				static fn (Mute $mute): string => $mute->actorId,
				$repository->forUser(
					'alice',
					10,
					before: Time::parse('2026-11-11T10:05:00Z'),
					beforeActorId: 'new',
				),
			),
		);

		self::assertSame(
			['new'],
			array_map(
				static fn (Mute $mute): string => $mute->actorId,
				$repository->forUser(
					'alice',
					10,
					after: Time::parse('2026-11-11T10:00:00Z'),
					afterActorId: 'old',
				),
			),
		);
	}

	public function testExpiredMutesAreHiddenButStillStored(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute(
			'alice',
			'bob',
			true,
			'2026-11-11T10:00:00Z',
			'2026-11-11T11:00:00Z',
		));

		$now = Time::parse('2026-11-11T11:00:00Z');

		self::assertNotNull($repository->find('alice', 'bob'));
		self::assertSame([], $repository->forUser('alice', 10, now: $now));
		self::assertSame([], $repository->actorIds('alice', $now));
		self::assertSame([], $repository->notificationActorIds('alice', $now));
		self::assertSame(['bob'], $repository->actorIds('alice', Time::parse('2026-11-11T10:59:59Z')));
	}

	public function testActorIdsOnlyIncludeNotificationMutesWhenRequested(): void
	{
		$repository = $this->repository();
		$repository->save($this->mute('alice', 'timeline-only', false));
		$repository->save($this->mute('alice', 'everything', true));
		$repository->save($this->mute('carol', 'other', true));

		self::assertSame(
			['timeline-only', 'everything'],
			$repository->actorIds('alice'),
		);
		self::assertSame(['everything'], $repository->notificationActorIds('alice'));
	}

	private function mute(
		string $userId,
		string $actorId,
		bool $notifications,
		string $createdAt = '2026-11-11T10:00:00Z',
		?string $expiresAt = null,
	): Mute {
		return new Mute(
			userId: $userId,
			actorId: $actorId,
			notifications: $notifications,
			createdAt: Time::parse($createdAt),
			expiresAt: $expiresAt === null ? null : Time::parse($expiresAt),
		);
	}
}
