<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublication;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both AT Protocol publication repositories must satisfy.
 */
abstract class AtprotoPublicationRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AtprotoPublicationRepository;

	public function testInsertAndFind(): void
	{
		$repository = $this->repository();
		$publication = $this->publication('alice', 'post-1', '234567abcdefg');

		$repository->insert($publication);

		$stored = $repository->findForPost('alice', 'post-1');
		self::assertNotNull($stored);
		self::assertSame('234567abcdefg', $stored->rkey);
		self::assertSame('at://did:plc:alice/app.bsky.feed.post/234567abcdefg', $stored->uri);
		self::assertNull($repository->findForPost('alice', 'other'));
		self::assertNull($repository->findForPost('bob', 'post-1'));
	}

	public function testThePostIsUniquePerAccount(): void
	{
		$repository = $this->repository();
		$repository->insert($this->publication('alice', 'post-1', '234567abcdefg'));

		$this->expectException(DatabaseException::class);
		$repository->insert($this->publication('alice', 'post-1', '234567abcdefh'));
	}

	public function testTheRecordKeyIsUniquePerAccount(): void
	{
		$repository = $this->repository();
		$repository->insert($this->publication('alice', 'post-1', '234567abcdefg'));

		$repository->insert($this->publication('bob', 'post-1', '234567abcdefg'));

		$this->expectException(DatabaseException::class);
		$repository->insert($this->publication('alice', 'post-2', '234567abcdefg'));
	}

	public function testRemoveIsScopedAndIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->publication('alice', 'post-1', '234567abcdefg'));

		self::assertFalse($repository->removeForPost('bob', 'post-1'));
		self::assertTrue($repository->removeForPost('alice', 'post-1'));
		self::assertFalse($repository->removeForPost('alice', 'post-1'));
		self::assertNull($repository->findForPost('alice', 'post-1'));
	}

	private function publication(string $user, string $post, string $rkey): AtprotoPublication
	{
		return new AtprotoPublication(
			$user,
			$post,
			$rkey,
			'at://did:plc:' . $user . '/app.bsky.feed.post/' . $rkey,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
