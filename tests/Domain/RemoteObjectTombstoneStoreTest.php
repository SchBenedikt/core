<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryRemoteObjectTombstoneStore;
use AlohaSocial\Core\Domain\Federation\PdoRemoteObjectTombstoneStore;
use AlohaSocial\Core\Domain\Federation\RemoteObjectTombstoneSchema;
use AlohaSocial\Core\Domain\Federation\RemoteObjectTombstoneStore;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The delete-before-create guard.
 *
 * Its guarantees — owner scoping, earliest-delete retention, expiry and the
 * row cap — are the same in both adapters, so they are asserted against both.
 */
final class RemoteObjectTombstoneStoreTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const OTHER_ACTOR = 'https://mallory.example/users/eve';

	private const OBJECT = 'https://remote.example/notes/1';

	public function testARecordedDeletionBlocksOnlyItsOwnActorAndIdentity(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->record(self::ACTOR, self::OBJECT, 'https://remote.example/activities/delete-1', $this->now());

			self::assertTrue($store->isDeleted(self::ACTOR, self::OBJECT, $this->now()), $name);
			self::assertFalse($store->isDeleted(self::OTHER_ACTOR, self::OBJECT, $this->now()), $name);
			self::assertFalse($store->isDeleted(self::ACTOR, 'https://remote.example/notes/2', $this->now()), $name);
		}
	}

	public function testTheEarliestDeletionIsTheOneThatCounts(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->record(self::ACTOR, self::OBJECT, 'delete-1', $this->now());
			$store->record(
				self::ACTOR,
				self::OBJECT,
				'delete-2',
				$this->now()->modify('+' . (RemoteObjectTombstoneStore::TTL_SECONDS - 60) . ' seconds'),
			);

			// The second record must not extend the window: expiry still
			// follows the first deletion.
			$later = $this->now()->modify('+' . (RemoteObjectTombstoneStore::TTL_SECONDS + 1) . ' seconds');
			self::assertFalse($store->isDeleted(self::ACTOR, self::OBJECT, $later), $name);
		}
	}

	public function testAnExpiredTombstoneNoLongerApplies(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->record(self::ACTOR, self::OBJECT, 'delete-1', $this->now());

			$later = $this->now()->modify('+' . (RemoteObjectTombstoneStore::TTL_SECONDS + 1) . ' seconds');

			self::assertFalse($store->isDeleted(self::ACTOR, self::OBJECT, $later), $name);
		}
	}

	public function testTheStoreEvictsTheOldestEntriesBeyondItsCap(): void
	{
		foreach ($this->stores() as $name => $store) {
			$total = RemoteObjectTombstoneStore::MAX_ENTRIES + 2;
			for ($i = 0; $i < $total; $i++) {
				$store->record(
					self::ACTOR,
					'https://remote.example/notes/' . $i,
					'delete-' . $i,
					$this->now()->modify('+' . $i . ' seconds'),
				);
			}

			$checkAt = $this->now()->modify('+1 hour');
			self::assertFalse(
				$store->isDeleted(self::ACTOR, 'https://remote.example/notes/0', $checkAt),
				$name,
			);
			self::assertTrue(
				$store->isDeleted(self::ACTOR, 'https://remote.example/notes/' . ($total - 1), $checkAt),
				$name,
			);
		}
	}

	/**
	 * @return array<string, RemoteObjectTombstoneStore>
	 */
	private function stores(): array
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RemoteObjectTombstoneSchema::create($connection);

		return [
			'in-memory' => new InMemoryRemoteObjectTombstoneStore(),
			'sql' => new PdoRemoteObjectTombstoneStore($connection),
		];
	}

	private function now(): \DateTimeImmutable
	{
		return Time::parse('2026-11-11T10:00:00Z');
	}
}
