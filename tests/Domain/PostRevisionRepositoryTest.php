<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\InMemoryPostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PostRevision;
use AlohaSocial\Core\Domain\Post\PostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Both revision adapters against the same expectations.
 *
 * A revision is append-only and keyed by post and version; that is what the
 * edit history relies on, so the in-memory and the SQL store must agree.
 */
final class PostRevisionRepositoryTest extends TestCase
{
	public function testStoresAndReadsVersionsOldestFirst(): void
	{
		foreach ($this->repositories() as $name => $repository) {
			$repository->add($this->revision('post-1', 2, 'second'));
			$repository->add($this->revision('post-1', 1, 'first'));

			$history = $repository->forPost('post-1');

			self::assertCount(2, $history, $name);
			self::assertSame([1, 2], array_column($history, 'revision'), $name);
			self::assertSame(['first', 'second'], array_column($history, 'content'), $name);
			self::assertSame(2, $repository->countForPost('post-1'), $name);
		}
	}

	public function testKeepsTheWholeSnapshot(): void
	{
		foreach ($this->repositories() as $name => $repository) {
			$repository->add(new PostRevision(
				postId: 'post-2',
				revision: 3,
				content: 'text',
				contentWarning: 'cw',
				visibility: Visibility::Unlisted,
				attachmentIds: ['a1', 'a2'],
				editedAt: Time::parse('2026-11-11T10:05:00Z'),
			));

			$stored = $repository->forPost('post-2')[0];

			self::assertSame('text', $stored->content, $name);
			self::assertSame('cw', $stored->contentWarning, $name);
			self::assertSame(Visibility::Unlisted, $stored->visibility, $name);
			self::assertSame(['a1', 'a2'], $stored->attachmentIds, $name);
			self::assertSame('2026-11-11T10:05:00Z', Time::format($stored->editedAt), $name);
		}
	}

	public function testAnotherPostHasItsOwnHistory(): void
	{
		foreach ($this->repositories() as $name => $repository) {
			$repository->add($this->revision('post-1', 1, 'first'));
			$repository->add($this->revision('post-2', 1, 'other'));

			self::assertCount(1, $repository->forPost('post-1'), $name);
			self::assertCount(1, $repository->forPost('post-2'), $name);
			self::assertSame([], $repository->forPost('post-3'), $name);
			self::assertSame(0, $repository->countForPost('post-3'), $name);
		}
	}

	public function testARepositoryStartsEmpty(): void
	{
		foreach ($this->repositories() as $name => $repository) {
			self::assertSame([], $repository->forPost('post-1'), $name);
		}
	}

	public function testAVersionIsOnlyStoredOnce(): void
	{
		foreach ($this->repositories() as $name => $repository) {
			$repository->add($this->revision('post-1', 1, 'first'));

			try {
				$repository->add($this->revision('post-1', 1, 'rewritten'));
				self::fail('Expected the duplicate revision to be refused for ' . $name);
			} catch (DatabaseException) {
				// The history cannot be rewritten.
			}

			self::assertSame('first', $repository->forPost('post-1')[0]->content, $name);
			self::assertSame(1, $repository->countForPost('post-1'), $name);
		}
	}

	/**
	 * @return array<string, PostRevisionRepository>
	 */
	private function repositories(): array
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($connection);

		return [
			'in-memory' => new InMemoryPostRevisionRepository(),
			'sql' => new PdoPostRevisionRepository($connection),
		];
	}

	private function revision(string $postId, int $revision, string $content): PostRevision
	{
		return new PostRevision(
			postId: $postId,
			revision: $revision,
			content: $content,
			contentWarning: null,
			visibility: Visibility::Public,
			attachmentIds: [],
			editedAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
