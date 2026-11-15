<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Collection\MediaCollection;
use AlohaSocial\Core\Domain\Collection\MediaCollectionRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The collection contract both adapters must satisfy.
 */
abstract class MediaCollectionRepositoryTestCase extends TestCase
{
	abstract protected function repository(): MediaCollectionRepository;

	public function testCollectionsAreOwnerScopedAndNewestFirst(): void
	{
		$repository = $this->repository();
		$repository->insert($this->collection('c1', 'alice', '2026-11-15T10:00:00Z'));
		$repository->insert($this->collection('c2', 'alice', '2026-11-15T11:00:00Z'));
		$repository->insert($this->collection('c3', 'bob', '2026-11-15T12:00:00Z'));

		self::assertSame(['c2', 'c1'], array_map(
			static fn (MediaCollection $collection): string => $collection->id,
			$repository->forOwner('alice'),
		));
		self::assertSame(['c2'], array_map(
			static fn (MediaCollection $collection): string => $collection->id,
			$repository->forOwnerPage('alice', 1, 0),
		));
		self::assertSame(2, $repository->countForOwner('alice'));
		self::assertSame(1, $repository->countForOwner('bob'));
		self::assertSame('alice', $repository->find('c1')?->ownerId);
	}

	public function testUpdateReplacesTheDetailsWithoutTouchingTheOwner(): void
	{
		$repository = $this->repository();
		$collection = $this->collection('c1', 'alice', '2026-11-15T10:00:00Z');
		$repository->insert($collection);

		$repository->update($collection->withDetails(
			'Renamed',
			'New description',
			Visibility::Public,
			Time::parse('2026-11-15T12:00:00Z'),
		));

		$stored = $repository->find('c1');
		self::assertSame('Renamed', $stored?->title);
		self::assertSame('New description', $stored?->description);
		self::assertSame(Visibility::Public, $stored?->audience);
		self::assertSame('alice', $stored?->ownerId);
	}

	public function testItemsAreOrderedAndUniqueByPositionAndPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->collection('c1', 'alice', '2026-11-15T10:00:00Z'));
		$repository->addItem(new \AlohaSocial\Core\Domain\Collection\MediaCollectionItem('c1', 'p2', 2, Time::parse('2026-11-15T10:01:00Z')));
		$repository->addItem(new \AlohaSocial\Core\Domain\Collection\MediaCollectionItem('c1', 'p1', 1, Time::parse('2026-11-15T10:02:00Z')));

		self::assertSame(['p1', 'p2'], array_map(
			static fn ($item): string => $item->postId,
			$repository->items('c1', 10, 0),
		));
		self::assertSame(['p2'], array_map(
			static fn ($item): string => $item->postId,
			$repository->items('c1', 1, 1),
		));
		self::assertSame(2, $repository->countItems('c1'));
		self::assertSame(1, $repository->findItem('c1', 'p1')?->position);

		try {
			$repository->addItem(new \AlohaSocial\Core\Domain\Collection\MediaCollectionItem('c1', 'p1', 3, Time::parse('2026-11-15T10:03:00Z')));
			self::fail('A post can only appear once in a collection.');
		} catch (DatabaseException) {
			// expected
		}

		$this->expectException(DatabaseException::class);

		$repository->addItem(new \AlohaSocial\Core\Domain\Collection\MediaCollectionItem('c1', 'p3', 1, Time::parse('2026-11-15T10:04:00Z')));
	}

	public function testDeletingACollectionRemovesItsItemsAndIsIndependentOfThePost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->collection('c1', 'alice', '2026-11-15T10:00:00Z'));
		$repository->addItem(new \AlohaSocial\Core\Domain\Collection\MediaCollectionItem('c1', 'p1', 1, Time::parse('2026-11-15T10:01:00Z')));

		$repository->delete('c1');

		self::assertNull($repository->find('c1'));
		self::assertSame(0, $repository->countItems('c1'));
		self::assertFalse($repository->removeItem('c1', 'p1'));
		self::assertTrue($repository->removeItem('missing', 'p1') === false);
	}

	private function collection(string $id, string $ownerId, string $createdAt): MediaCollection
	{
		return new MediaCollection(
			$id,
			$ownerId,
			'Collection ' . $id,
			'Description',
			Visibility::Followers,
			Time::parse($createdAt),
			Time::parse($createdAt),
		);
	}
}
