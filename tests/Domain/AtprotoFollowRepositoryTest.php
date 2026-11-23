<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class AtprotoFollowRepositoryTest extends TestCase
{
	public function testPendingRecordIsUpdatedAndRemovedOnlyAfterRemoteDelete(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		AtprotoFollowSchema::create($connection);
		$repository = new PdoAtprotoFollowRepository($connection);
		$pending = new AtprotoFollow(
			'alice', 'did:plc:alice', 'did:plc:bob', '3jzfcijpj2z2a', null, null,
			AtprotoFollow::PENDING, Time::parse('2026-11-20T10:00:00Z'),
		);

		$repository->save($pending);
		self::assertSame(AtprotoFollow::PENDING, $repository->findForSubject('alice', 'did:plc:bob')?->state);

		$active = new AtprotoFollow(
			'alice', 'did:plc:alice', 'did:plc:bob', $pending->rkey,
			'at://did:plc:alice/app.bsky.graph.follow/3jzfcijpj2z2a', 'bafy-follow',
			AtprotoFollow::ACTIVE, $pending->followedAt,
		);
		$repository->save($active);

		$stored = $repository->forUser('alice');
		self::assertCount(1, $stored);
		self::assertSame($active->toArray(), $stored[0]->toArray());
		self::assertTrue($repository->remove('alice', 'did:plc:bob'));
		self::assertFalse($repository->remove('alice', 'did:plc:bob'));
		self::assertNull($repository->findForSubject('alice', 'did:plc:bob'));
	}
}
