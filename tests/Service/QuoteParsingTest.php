<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * A remote Note's quote metadata is kept as a reference only; a peer's word
 * never becomes a trusted grant here.
 */
final class QuoteParsingTest extends TestCase
{
	private RemotePostFactory $factory;

	protected function setUp(): void
	{
		$this->factory = new RemotePostFactory(new FrozenClock(Time::parse('2026-11-11T10:00:00Z')));
	}

	public function testAFepQuoteIsPreservedAsAReference(): void
	{
		$post = $this->factory->fromCreate('https://remote.example/users/bob', $this->create([
			'quote' => 'https://social.example/api/v1/posts/target-1',
		]));

		self::assertSame('https://social.example/api/v1/posts/target-1', $post->quotedObjectId);
		self::assertSame('https://social.example/api/v1/posts/target-1', $post->toArray()['quoted_object_id']);
	}

	public function testALegacyQuoteUrlAndAnObjectIdAreAccepted(): void
	{
		$legacy = $this->factory->fromCreate('https://remote.example/users/bob', $this->create([
			'quoteUrl' => 'https://social.example/api/v1/posts/legacy',
		]));
		self::assertSame('https://social.example/api/v1/posts/legacy', $legacy->quotedObjectId);

		$object = $this->factory->fromCreate('https://remote.example/users/bob', $this->create([
			'quote' => ['id' => 'https://social.example/api/v1/posts/object'],
		]));
		self::assertSame('https://social.example/api/v1/posts/object', $object->quotedObjectId);
	}

	public function testANonHttpQuoteTargetIsRefused(): void
	{
		$this->expectException(InboxRejectedException::class);

		$this->factory->fromCreate('https://remote.example/users/bob', $this->create([
			'quote' => 'javascript:alert(1)',
		]));
	}

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function create(array $extra): array
	{
		return [
			'id' => 'https://remote.example/activities/1',
			'type' => 'Create',
			'actor' => 'https://remote.example/users/bob',
			'object' => [
				'id' => 'https://remote.example/notes/1',
				'type' => 'Note',
				'attributedTo' => 'https://remote.example/users/bob',
				'content' => 'remote words',
				'published' => '2026-11-11T09:00:00Z',
			] + $extra,
		];
	}
}
