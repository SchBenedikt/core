<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Image\GdImageProcessor;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class MediaDiscardTest extends TestCase
{
	public function testDiscardKeepsBlobsReferencedByALocalPost(): void
	{
		$now = Time::parse('2026-11-24T12:00:00Z');
		$posts = new InMemoryPostRepository();
		$remotePosts = new InMemoryRemotePostRepository();
		$attachments = new InMemoryAttachmentRepository($posts, $remotePosts);
		$blobs = new InMemoryBlobStore();
		$attachment = $this->attachment($now);
		$attachments->insert($attachment);
		$blobs->put($attachment->storageKey, 'original');
		$blobs->put($attachment->thumbnailKey, 'thumbnail');
		$posts->insert(new Post(
			id: 'post-1',
			authorId: 'alice',
			content: 'A post with media',
			visibility: Visibility::Public,
			publishedAt: $now,
			attachmentIds: [$attachment->id],
		));

		$this->media($attachments, $posts, $remotePosts, $blobs)->discard($attachment);

		self::assertNotNull($attachments->find($attachment->id));
		self::assertSame('original', $blobs->read($attachment->storageKey));
		self::assertSame('thumbnail', $blobs->read($attachment->thumbnailKey));
	}

	public function testDiscardKeepsBlobsReferencedByARemotePost(): void
	{
		$now = Time::parse('2026-11-24T12:00:00Z');
		$posts = new InMemoryPostRepository();
		$remotePosts = new InMemoryRemotePostRepository();
		$attachments = new InMemoryAttachmentRepository($posts, $remotePosts);
		$blobs = new InMemoryBlobStore();
		$attachment = $this->attachment($now);
		$attachments->insert($attachment);
		$blobs->put($attachment->storageKey, 'original');
		$blobs->put($attachment->thumbnailKey, 'thumbnail');
		$remotePosts->insert(new RemotePost(
			activityId: 'remote-activity',
			objectId: 'https://remote.example/posts/1',
			actorId: 'https://remote.example/actors/bob',
			kind: RemotePostKind::Post,
			content: 'A remote post with media',
			contentWarning: null,
			publishedAt: $now,
		));
		$remotePosts->linkAttachments('remote-activity', [$attachment->id]);

		self::assertTrue($this->media($attachments, $posts, $remotePosts, $blobs)->isAttachedToPost($attachment));
		$this->media($attachments, $posts, $remotePosts, $blobs)->discard($attachment);

		self::assertNotNull($attachments->find($attachment->id));
		self::assertSame('original', $blobs->read($attachment->storageKey));
		self::assertSame('thumbnail', $blobs->read($attachment->thumbnailKey));
	}

	public function testDiscardRemovesAnUnattachedRowAndItsBlobs(): void
	{
		$now = Time::parse('2026-11-24T12:00:00Z');
		$posts = new InMemoryPostRepository();
		$remotePosts = new InMemoryRemotePostRepository();
		$attachments = new InMemoryAttachmentRepository($posts, $remotePosts);
		$blobs = new InMemoryBlobStore();
		$attachment = $this->attachment($now);
		$attachments->insert($attachment);
		$blobs->put($attachment->storageKey, 'original');
		$blobs->put($attachment->thumbnailKey, 'thumbnail');

		$this->media($attachments, $posts, $remotePosts, $blobs)->discard($attachment);

		self::assertNull($attachments->find($attachment->id));
		self::assertFalse($blobs->exists($attachment->storageKey));
		self::assertFalse($blobs->exists($attachment->thumbnailKey));
	}

	private function attachment(\DateTimeImmutable $now): Attachment
	{
		return new Attachment(
			id: 'attachment-1',
			ownerId: 'alice',
			storageKey: 'media/attachment-1/original.png',
			thumbnailKey: 'media/attachment-1/thumbnail.png',
			mimeType: 'image/png',
			width: 1,
			height: 1,
			byteSize: 8,
			altText: null,
			createdAt: $now,
		);
	}

	private function media(
		InMemoryAttachmentRepository $attachments,
		InMemoryPostRepository $posts,
		InMemoryRemotePostRepository $remotePosts,
		InMemoryBlobStore $blobs,
	): MediaService {
		return new MediaService(
			attachments: $attachments,
			posts: $posts,
			blobs: $blobs,
			images: new GdImageProcessor(),
			config: new ArrayConfig([]),
			clock: new FrozenClock(Time::parse('2026-11-24T12:00:00Z')),
			ids: new RandomIdGenerator(),
			remotePosts: $remotePosts,
		);
	}
}
