<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InboundBlockSchema;
use AlohaSocial\Core\Domain\Federation\PdoInboundBlockRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoInboundBlockRepositoryTest extends TestCase
{
	public function testActivityUriUndoIsActorAndRecordSpecific(): void
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		InboundBlockSchema::create($database);
		$repository = new PdoInboundBlockRepository($database);

		$repository->block('alice', 'https://remote.example/users/alice', 'https://remote.example/activities/block-1');
		self::assertTrue($repository->isBlocked('alice', 'https://remote.example/users/alice'));
		self::assertFalse($repository->unblockByActivityId('https://attacker.example/users/forged', 'https://remote.example/activities/block-1'));
		self::assertFalse($repository->unblockByActivityId('https://remote.example/users/alice', 'https://remote.example/activities/other'));
		self::assertFalse($repository->unblockByActivityId('https://remote.example/users/alice', 'https://remote.example/activities/block-1', 'mallory'));
		self::assertTrue($repository->isBlocked('alice', 'https://remote.example/users/alice'));

		self::assertTrue($repository->unblockByActivityId('https://remote.example/users/alice', 'https://remote.example/activities/block-1'));
		self::assertFalse($repository->isBlocked('alice', 'https://remote.example/users/alice'));
		self::assertFalse($repository->unblockByActivityId('https://remote.example/users/alice', 'https://remote.example/activities/block-1'));
	}

	public function testARepeatedBlockReplacesOnlyItsUndoUri(): void
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		InboundBlockSchema::create($database);
		$repository = new PdoInboundBlockRepository($database);
		$actor = 'https://remote.example/users/alice';
		$repository->block('alice', $actor, 'https://remote.example/activities/block-old');
		$repository->block('alice', $actor, 'https://remote.example/activities/block-new');

		self::assertFalse($repository->unblockByActivityId($actor, 'https://remote.example/activities/block-old'));
		self::assertTrue($repository->isBlocked('alice', $actor));
		self::assertTrue($repository->unblockByActivityId($actor, 'https://remote.example/activities/block-new'));
	}
}
