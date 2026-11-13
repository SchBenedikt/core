<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class AttachmentRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AttachmentRepository;

	public function testInsertAndFindPreservesMetadata(): void
	{
		$repository = $this->repository();
		$attachment = $this->attachment('a1', 'alice');

		$repository->insert($attachment);
		$stored = $repository->find('a1');

		self::assertNotNull($stored);
		self::assertSame($attachment->toArray(), $stored->toArray());
		self::assertSame('media/a1/original.png', $stored->storageKey);
		self::assertSame('media/a1/thumbnail.png', $stored->thumbnailKey);
		self::assertSame('a description', $stored->altText);
	}

	public function testFindManyReturnsOnlyKnownRowsKeyedById(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice'));
		$repository->insert($this->attachment('a2', 'bob'));

		self::assertSame(2, $repository->count());

		$found = $repository->findMany(['a2', 'missing', 'a1']);

		self::assertCount(2, $found);
		self::assertArrayHasKey('a1', $found);
		self::assertArrayHasKey('a2', $found);
		self::assertSame('bob', $found['a2']->ownerId);
		self::assertSame([], $repository->findMany([]));
	}

	public function testANullAltTextSurvivesTheRoundTrip(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice', null));

		self::assertNull($repository->find('a1')?->altText);
	}

	public function testUpdateAltTextChangesOnlyTheDescription(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice', 'old description'));

		$repository->updateAltText('a1', 'new description');

		$stored = $repository->find('a1');
		self::assertNotNull($stored);
		self::assertSame('new description', $stored->altText);
		self::assertSame('media/a1/original.png', $stored->storageKey);
		self::assertSame('media/a1/thumbnail.png', $stored->thumbnailKey);

		$repository->updateAltText('a1', null);
		self::assertNull($repository->find('a1')?->altText);

		// A caller that lost a race may still issue the update; an unknown id
		// must not create a row or throw.
		$repository->updateAltText('missing', 'ignored');
		self::assertNull($repository->find('missing'));
	}

	public function testSourceUrlsRoundTripAndAreUnique(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice', 'alt', 'https://remote.example/media/a.png'));

		self::assertSame(
			'a1',
			$repository->findBySourceUrl('https://remote.example/media/a.png')?->id,
		);
		self::assertNull($repository->findBySourceUrl('https://remote.example/media/missing.png'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->attachment('a2', 'alice', null, 'https://remote.example/media/a.png'));
	}

	public function testDeleteRemovesTheRowAndIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice'));

		$repository->delete('a1');

		self::assertNull($repository->find('a1'));
		self::assertSame(0, $repository->count());
		$repository->delete('a1');
	}

	public function testUnattachedCleanupCandidatesAreOldestFirstAndConditional(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachmentAt('old', '2026-11-11T10:00:00Z'));
		$repository->insert($this->attachmentAt('new', '2026-11-11T10:10:00Z'));
		$repository->insert($this->attachmentAt('mid', '2026-11-11T10:05:00Z'));

		self::assertSame(2, $repository->countUnattachedBefore(Time::parse('2026-11-11T10:10:00Z')));
		self::assertSame(
			['old'],
			array_map(
				static fn (Attachment $attachment): string => $attachment->id,
				$repository->unattachedBefore(Time::parse('2026-11-11T10:10:00Z'), 1),
			),
		);

		self::assertTrue($repository->deleteIfUnattached('old'));
		self::assertFalse($repository->deleteIfUnattached('old'));
		self::assertSame(1, $repository->countUnattachedBefore(Time::parse('2026-11-11T10:10:00Z')));
		self::assertSame([], $repository->unattachedBefore(Time::parse('2026-11-11T10:00:00Z'), 10));
	}

	public function testADuplicateIdIsRefused(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('a1', 'alice'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->attachment('a1', 'bob'));
	}

	protected function attachmentAt(string $id, string $createdAt): Attachment
	{
		return new Attachment(
			id: $id,
			ownerId: 'alice',
			storageKey: 'media/' . $id . '/original.png',
			thumbnailKey: 'media/' . $id . '/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse($createdAt),
		);
	}

	protected function attachment(
		string $id,
		string $ownerId,
		?string $altText = 'a description',
		?string $sourceUrl = null,
	): Attachment
	{
		return new Attachment(
			id: $id,
			ownerId: $ownerId,
			storageKey: 'media/' . $id . '/original.png',
			thumbnailKey: 'media/' . $id . '/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: $altText,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
			sourceUrl: $sourceUrl,
		);
	}
}
