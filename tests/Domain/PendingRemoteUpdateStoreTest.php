<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryPendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\PdoPendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdate;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateSchema;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateStore;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The buffer for updates that arrive before their post.
 *
 * Its two hard guarantees — an entry is consumed once, and the buffer cannot
 * grow without bound — are the same in both adapters, so they are asserted
 * against both.
 */
final class PendingRemoteUpdateStoreTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const OTHER_ACTOR = 'https://mallory.example/users/eve';

	private const OBJECT = 'https://remote.example/notes/1';

	public function testAnEntryIsHandedBackOnce(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->store($this->pending(self::OBJECT, 'edited text'));

			$taken = $store->take(self::OBJECT, self::ACTOR, $this->now());

			self::assertNotNull($taken, $name);
			self::assertSame('edited text', $taken->content, $name);
			self::assertSame(self::ACTOR, $taken->actorId, $name);
			self::assertNull($store->take(self::OBJECT, self::ACTOR, $this->now()), $name);
		}
	}

	public function testAnotherActorCannotConsumeOrReadTheEntry(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->store($this->pending(self::OBJECT, 'edited text'));

			self::assertNull($store->take(self::OBJECT, self::OTHER_ACTOR, $this->now()), $name);
			self::assertNull($store->take(self::OBJECT, self::ACTOR, $this->now()), $name);
		}
	}

	public function testAnExpiredEntryIsDropped(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->store($this->pending(self::OBJECT, 'edited text'));

			$later = $this->now()->modify('+' . (PendingRemoteUpdateStore::TTL_SECONDS + 1) . ' seconds');

			self::assertNull($store->take(self::OBJECT, self::ACTOR, $later), $name);
		}
	}

	public function testASecondUpdateReplacesTheFirst(): void
	{
		foreach ($this->stores() as $name => $store) {
			$store->store($this->pending(self::OBJECT, 'first edit'));
			$store->store($this->pending(self::OBJECT, 'second edit'));

			$taken = $store->take(self::OBJECT, self::ACTOR, $this->now());

			self::assertNotNull($taken, $name);
			self::assertSame('second edit', $taken->content, $name);
		}
	}

	public function testTheBufferEvictsTheOldestEntriesBeyondItsCap(): void
	{
		foreach ($this->stores() as $name => $store) {
			$total = PendingRemoteUpdateStore::MAX_ENTRIES + 2;
			for ($i = 0; $i < $total; $i++) {
				$store->store($this->pending(
					'https://remote.example/notes/' . $i,
					'edit ' . $i,
					$this->now()->modify('+' . $i . ' seconds'),
				));
			}

			self::assertNull(
				$store->take('https://remote.example/notes/0', self::ACTOR, $this->now()->modify('+5 minutes')),
				$name,
			);
			self::assertNotNull(
				$store->take('https://remote.example/notes/' . ($total - 1), self::ACTOR, $this->now()->modify('+5 minutes')),
				$name,
			);
		}
	}

	/**
	 * @return array<string, PendingRemoteUpdateStore>
	 */
	private function stores(): array
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		PendingRemoteUpdateSchema::create($connection);

		return [
			'in-memory' => new InMemoryPendingRemoteUpdateStore(),
			'sql' => new PdoPendingRemoteUpdateStore($connection),
		];
	}

	private function now(): \DateTimeImmutable
	{
		return Time::parse('2026-11-11T10:00:00Z');
	}

	private function pending(
		string $objectId,
		string $content,
		?\DateTimeImmutable $receivedAt = null,
	): PendingRemoteUpdate {
		return new PendingRemoteUpdate(
			objectId: $objectId,
			actorId: self::ACTOR,
			activityId: $objectId . '#update',
			content: $content,
			contentWarning: null,
			updatedAt: $this->now(),
			receivedAt: $receivedAt ?? $this->now(),
		);
	}
}
