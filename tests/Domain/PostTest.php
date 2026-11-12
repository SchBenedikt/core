<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
	public function testExposesTheApiShape(): void
	{
		$post = new Post(
			id: 'p1',
			authorId: 'alice',
			content: 'hello',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T10:00:00Z'),
			contentWarning: 'spoilers',
		);

		self::assertSame([
			'id' => 'p1',
			'author_id' => 'alice',
			'content' => 'hello',
			'content_warning' => 'spoilers',
			'visibility' => 'public',
			'published_at' => '2026-11-11T10:00:00Z',
			'deleted_at' => null,
			'poll' => null,
		], $post->toArray());
	}

	public function testIsDeletedFollowsTheTimestamp(): void
	{
		$post = new Post(
			'p1',
			'alice',
			'hello',
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
			null,
			Time::parse('2026-11-11T11:00:00Z'),
		);

		self::assertTrue($post->isDeleted());
		self::assertSame('2026-11-11T11:00:00Z', $post->toArray()['deleted_at']);
	}

	public function testRequiresAnId(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Post('', 'alice', 'x', Visibility::Public, Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testRequiresAnAuthor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Post('p1', '', 'x', Visibility::Public, Time::parse('2026-11-11T10:00:00Z'));
	}
}
