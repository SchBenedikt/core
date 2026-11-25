<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Conversation\ConversationMarker;
use AlohaSocial\Core\Domain\Conversation\ConversationMarkerSchema;
use AlohaSocial\Core\Domain\Conversation\PdoConversationMarkerRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConversationMarkerRepositoryTest extends TestCase
{
	public function testMarkersArePrivateAndCanAdvanceBothWatermarks(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		ConversationMarkerSchema::create($connection);
		$repository = new PdoConversationMarkerRepository($connection);
		$marker = new ConversationMarker(
			id: ConversationMarker::idFor('alice', 'root-1'),
			userId: 'alice',
			conversationId: 'root-1',
			readThroughPostId: 'post-1',
			readThroughAt: Time::parse('2026-11-25T10:00:00Z'),
		);

		$repository->save($marker);
		$repository->save(new ConversationMarker(
			id: $marker->id,
			userId: $marker->userId,
			conversationId: $marker->conversationId,
			readThroughPostId: 'post-2',
			readThroughAt: Time::parse('2026-11-25T10:01:00Z'),
			dismissedThroughPostId: 'post-2',
			dismissedThroughAt: Time::parse('2026-11-25T10:01:00Z'),
		));

		$saved = $repository->find('alice', 'root-1');
		self::assertSame('post-2', $saved?->readThroughPostId);
		self::assertSame('post-2', $saved?->dismissedThroughPostId);
		self::assertNull($repository->find('bob', 'root-1'));
	}
}
