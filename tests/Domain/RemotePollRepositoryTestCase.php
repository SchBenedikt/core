<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\RemotePoll;
use AlohaSocial\Core\Domain\Federation\RemotePollOption;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both remote poll stores share.
 */
abstract class RemotePollRepositoryTestCase extends TestCase
{
	abstract protected function repository(): RemotePollRepository;

	public function testAPollRoundTripsWithOptionsAndSchedule(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->poll());

		$found = $repository->find('https://remote.example/questions/1');

		self::assertNotNull($found);
		self::assertSame('Which one?', $found->title);
		self::assertFalse($found->multiple);
		self::assertCount(3, $found->options);
		self::assertSame('One', $found->options[0]->title);
		self::assertSame(3, $found->options[0]->votesCount);
		self::assertSame('2026-12-01T12:00:00Z', Time::format($found->endsAt));
		self::assertNull($found->closedAt);
		self::assertNull($repository->find('missing'));
	}

	public function testAnUpsertReplacesThePeerView(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->poll());
		$repository->upsert($this->poll(
			multiple: true,
			options: [new RemotePollOption('One', 10), new RemotePollOption('Two', 20)],
		));

		$found = $repository->find('https://remote.example/questions/1');

		self::assertNotNull($found);
		self::assertTrue($found->multiple);
		self::assertCount(2, $found->options);
		self::assertSame(20, $found->options[1]->votesCount);
	}

	public function testMarkClosedIsIdempotentAndKeepsTheOptions(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->poll());

		self::assertTrue($repository->markClosed('https://remote.example/questions/1', Time::parse('2026-12-02T00:00:00Z')));
		self::assertFalse($repository->markClosed('https://remote.example/questions/1', Time::parse('2026-12-03T00:00:00Z')));

		$found = $repository->find('https://remote.example/questions/1');
		self::assertNotNull($found);
		self::assertSame('2026-12-02T00:00:00Z', Time::format($found->closedAt));
		self::assertCount(3, $found->options);
	}

	public function testAnOlderCreateCannotReopenAClosedPoll(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->poll());
		$repository->markClosed('https://remote.example/questions/1', Time::parse('2026-12-02T00:00:00Z'));

		$repository->upsert($this->poll());

		self::assertNotNull($repository->find('https://remote.example/questions/1')?->closedAt);
	}

	public function testTheAudienceSurvivesTheRoundTrip(): void
	{
		$repository = $this->repository();
		$repository->upsert($this->poll(audience: Visibility::Followers));

		self::assertSame(
			Visibility::Followers,
			$repository->find('https://remote.example/questions/1')?->audience,
		);
	}

	/**
	 * @param list<RemotePollOption>|null $options
	 */
	private function poll(
		bool $multiple = false,
		?array $options = null,
		?Visibility $audience = null,
	): RemotePoll
	{
		return new RemotePoll(
			objectId: 'https://remote.example/questions/1',
			actorId: 'https://remote.example/users/bob',
			activityId: 'https://remote.example/activities/1',
			title: 'Which one?',
			options: $options ?? [
				new RemotePollOption('One', 3),
				new RemotePollOption('Two', 1),
				new RemotePollOption('Three'),
			],
			multiple: $multiple,
			endsAt: Time::parse('2026-12-01T12:00:00Z'),
			closedAt: null,
			votersCount: 4,
			updatedAt: Time::parse('2026-11-11T10:00:00Z'),
			audience: $audience,
		);
	}
}
