<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Collection\MediaCollection;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\Collection\MediaCollectionNotFoundException;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class MediaCollectionServiceTest extends TestCase
{
	private Application $app;

	private InMemoryAttachmentRepository $attachments;

	protected function setUp(): void
	{
		$this->attachments = new InMemoryAttachmentRepository();
		$this->attachments->insert($this->attachment('a1', 'alice', 'image/png'));
		$this->attachments->insert($this->attachment('a2', 'alice', 'image/png'));
		$this->attachments->insert($this->attachment('a3', 'alice', 'video/mp4', MediaKind::Video));
		$this->attachments->insert($this->attachment('a4', 'alice', 'audio/mpeg', MediaKind::Audio));
		$this->attachments->insert($this->attachment('b1', 'bob', 'image/png'));

		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-15T12:00:00Z')),
			config: new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]),
			attachmentRepository: $this->attachments,
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
		);
	}

	public function testACollectionListsOnlyVisibleImageOrVideoPosts(): void
	{
		$collection = $this->collection();
		$image = $this->post('alice', ['a1']);
		$video = $this->post('alice', ['a3']);

		$this->collections()->addItem('alice', $collection->id, $image->id);
		$this->collections()->addItem('alice', $collection->id, $video->id);

		$shown = $this->collections()->show(null, $collection->id);

		self::assertSame(2, $shown['item_count']);
		self::assertSame([$image->id, $video->id], array_map(
			static fn (array $item): string => $item['post']['id'],
			$shown['items'],
		));
		self::assertSame('a1', $shown['cover']['id']);
		self::assertSame('image', $shown['cover']['media_type']);
	}

	public function testAFollowersCollectionIsHiddenUntilTheViewerFollowsTheOwner(): void
	{
		$collection = $this->collection(Visibility::Followers);
		$post = $this->post('alice', ['a1']);
		$this->collections()->addItem('alice', $collection->id, $post->id);

		foreach ([null, 'bob'] as $viewer) {
			try {
				$this->collections()->show($viewer, $collection->id);
				self::fail('A followers-only collection must not be visible to ' . ($viewer ?? 'anonymous') . '.');
			} catch (MediaCollectionNotFoundException) {
				// expected
			}
		}

		self::assertSame([], $this->collections()->forActor('alice', 'bob'));

		$this->followBob();
		$shown = $this->collections()->show('bob', $collection->id);

		self::assertSame(1, $shown['item_count']);
		self::assertCount(1, $this->collections()->forActor('alice', 'bob'));
		self::assertCount(1, $this->collections()->show('alice', $collection->id)['items']);
	}

	public function testOnlyOwnPostsWithAStillOrVideoCanBeAdded(): void
	{
		$collection = $this->collection();
		$foreign = $this->post('bob', ['b1']);
		$audio = $this->post('alice', ['a4']);
		$textOnly = $this->post('alice');

		foreach ([$foreign, $audio, $textOnly] as $post) {
			try {
				$this->collections()->addItem('alice', $collection->id, $post->id);
				self::fail('The post ' . $post->id . ' must not be added to the collection.');
			} catch (ValidationException $exception) {
				self::assertArrayHasKey('post_id', $exception->fields());
			}
		}
	}

	public function testDuplicateAddsAreIdempotentAndRemovalKeepsThePost(): void
	{
		$collection = $this->collection();
		$post = $this->post('alice', ['a1']);

		$first = $this->collections()->addItem('alice', $collection->id, $post->id);
		$second = $this->collections()->addItem('alice', $collection->id, $post->id);

		self::assertSame(1, $first->position);
		self::assertSame(1, $second->position);
		self::assertSame(1, $this->collections()->show('alice', $collection->id)['item_count']);

		$this->collections()->removeItem('alice', $collection->id, $post->id);
		$this->collections()->removeItem('alice', $collection->id, $post->id);

		self::assertSame(0, $this->collections()->show('alice', $collection->id)['item_count']);
		self::assertSame($post->id, $this->app->posts->getVisible($post->id, 'alice')->id);
	}

	public function testOnlyTheOwnerMayChangeOrDeleteACollection(): void
	{
		$collection = $this->collection();

		foreach ([
			fn () => $this->collections()->update('bob', $collection->id, 'Stolen', '', Visibility::Public),
			fn () => $this->collections()->delete('bob', $collection->id),
		] as $attempt) {
			try {
				$attempt();
				self::fail('A foreign owner must not change the collection.');
			} catch (MediaCollectionNotFoundException) {
				// expected
			}
		}

		self::assertNotNull($this->app->collections?->show('alice', $collection->id));
	}

	public function testValidationRejectsDirectAudienceAndAnEmptyTitle(): void
	{
		foreach ([
			fn () => $this->collections()->create('alice', '', '', Visibility::Public),
			fn () => $this->collections()->create('alice', 'Album', '', Visibility::Direct),
		] as $attempt) {
			try {
				$attempt();
				self::fail('Invalid collection details must be rejected.');
			} catch (ValidationException $exception) {
				self::assertNotSame([], $exception->fields());
			}
		}
	}

	private function collection(Visibility $audience = Visibility::Public): MediaCollection
	{
		return $this->collections()->create('alice', 'Album', 'A description', $audience);
	}

	private function collections(): \AlohaSocial\Core\Service\Collection\MediaCollectionService
	{
		$collections = $this->app->collections;
		self::assertNotNull($collections);

		return $collections;
	}

	private function followBob(): void
	{
		$this->app->followers->follow(
			new FollowActor(
				localUserId: 'alice',
				actorId: 'https://social.example/@bob',
				inboxUrl: 'https://social.example/inbox',
			),
			local: true,
		);
	}

	/**
	 * @param list<string> $attachmentIds
	 */
	private function post(string $authorId, array $attachmentIds = []): Post
	{
		return $this->app->posts->create(new CreatePost(
			authorId: $authorId,
			content: 'A collection post',
			attachmentIds: $attachmentIds,
		));
	}

	private function attachment(string $id, string $ownerId, string $mimeType, MediaKind $kind = MediaKind::Image): Attachment
	{
		return new Attachment(
			id: $id,
			ownerId: $ownerId,
			storageKey: 'media/' . $id . '/original',
			thumbnailKey: 'media/' . $id . '/thumbnail',
			mimeType: $mimeType,
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse('2026-11-15T11:00:00Z'),
			kind: $kind,
		);
	}
}
