<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MuteServiceTest extends TestCase
{
	private FrozenClock $clock;

	private MuteService $mutes;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-13T12:00:00Z'));
		$this->mutes = new MuteService(new InMemoryMuteRepository(), $this->clock);
	}

	public function testAnIndefiniteMuteStoresTheSettingsAndIsActive(): void
	{
		$mute = $this->mutes->mute('alice', 'bob', true);

		self::assertSame('alice', $mute->userId);
		self::assertSame('bob', $mute->actorId);
		self::assertTrue($mute->notifications);
		self::assertNull($mute->expiresAt);
		self::assertTrue($this->mutes->isMuted('alice', 'bob'));
		self::assertSame(['bob'], $this->mutes->actorIds('alice'));
		self::assertSame(['bob'], $this->mutes->notificationActorIds('alice'));
	}

	public function testARepeatUpdatesTheSameMuteAndRestartsTheExpiry(): void
	{
		$this->mutes->mute('alice', 'bob', false, 60);
		$this->clock->advance(30);
		$mute = $this->mutes->mute('alice', 'bob', true, 120);

		self::assertTrue($mute->notifications);
		self::assertSame(
			'2026-11-13T12:02:30Z',
			$mute->expiresAt?->format('Y-m-d\TH:i:s\Z'),
		);
		self::assertSame(['bob'], $this->mutes->notificationActorIds('alice'));
		self::assertCount(1, $this->mutes->forUser('alice', 10));
	}

	public function testAnExpiredMuteIsHiddenWithoutDeletingIt(): void
	{
		$this->mutes->mute('alice', 'bob', true, 60);
		$this->clock->advance(60);

		self::assertNull($this->mutes->find('alice', 'bob'));
		self::assertFalse($this->mutes->isMuted('alice', 'bob'));
		self::assertSame([], $this->mutes->forUser('alice', 10));
		self::assertSame([], $this->mutes->actorIds('alice'));
		self::assertSame([], $this->mutes->notificationActorIds('alice'));

		$again = $this->mutes->mute('alice', 'bob');
		self::assertNull($again->expiresAt);
		self::assertTrue($this->mutes->isMuted('alice', 'bob'));
	}

	public function testUnmuteIsIdempotentAndScopedToTheOwner(): void
	{
		$this->mutes->mute('alice', 'bob');

		self::assertFalse($this->mutes->unmute('carol', 'bob'));
		self::assertTrue($this->mutes->isMuted('alice', 'bob'));

		self::assertTrue($this->mutes->unmute('alice', 'bob'));
		self::assertFalse($this->mutes->isMuted('alice', 'bob'));
		self::assertFalse($this->mutes->unmute('alice', 'bob'));
	}

	public function testADurationOutsideTheSupportedRangeIsRejected(): void
	{
		foreach ([0, -1, MuteService::MAX_DURATION_SECONDS + 1] as $duration) {
			try {
				$this->mutes->mute('alice', 'bob', false, $duration);
				self::fail('Expected the duration to be rejected: ' . $duration);
			} catch (InvalidArgumentException) {
				self::assertFalse($this->mutes->isMuted('alice', 'bob'));
			}
		}
	}

	public function testAnEmptyActorIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->mutes->mute('alice', '');
	}

	public function testTheListCanBePagedByTheMuteCursor(): void
	{
		$this->mutes->mute('alice', 'old');
		$this->clock->advance(1);
		$this->mutes->mute('alice', 'new');

		$page = $this->mutes->forUser(
			'alice',
			10,
			before: Time::parse('2026-11-13T12:00:01Z'),
			beforeActorId: 'new',
		);

		self::assertSame(['old'], array_map(static fn ($mute): string => $mute->actorId, $page));
	}
}
