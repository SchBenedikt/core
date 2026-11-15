<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class PinsAndCollectionsApiTest extends TestCase
{
	private Application $app;

	private InMemoryAttachmentRepository $attachments;

	protected function setUp(): void
	{
		$this->attachments = new InMemoryAttachmentRepository();
		$this->attachments->insert($this->attachment('a1'));
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-15T12:00:00Z')),
			config: new ArrayConfig(['social' => [
				'instance.url' => 'https://social.example',
				'mastodon.api' => 'true',
			]]),
			signingKey: RsaKeyPair::generate(),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			attachmentRepository: $this->attachments,
		);
	}

	public function testArchiveIsReversibleKeepsTheUrlAndHidesOnlyLocalDiscovery(): void
	{
		$post = $this->post('alice', ['a1']);
		$before = $this->request('GET', '/api/v1/timelines/public');
		self::assertSame(200, $before->status);
		self::assertContains($post->id, $this->ids($before->decodedBody()['posts']));

		$archive = $this->request('POST', '/api/v1/posts/' . $post->id . '/archive', [], 'alice');
		self::assertSame(200, $archive->status);
		self::assertNotNull($archive->decodedBody()['post']['archived_at']);

		$detail = $this->request('GET', '/api/v1/posts/' . $post->id);
		self::assertSame(200, $detail->status);
		self::assertNotNull($detail->decodedBody()['post']['archived_at']);

		$public = $this->request('GET', '/api/v1/timelines/public');
		self::assertNotContains($post->id, $this->ids($public->decodedBody()['posts']));

		$archived = $this->request('GET', '/api/v1/posts/archived', [], 'alice');
		self::assertSame(200, $archived->status);
		self::assertContains($post->id, $this->ids($archived->decodedBody()['posts']));
		self::assertSame(401, $this->request('GET', '/api/v1/posts/archived')->status);
		self::assertSame(403, $this->request('POST', '/api/v1/posts/' . $post->id . '/archive', [], 'bob')->status);

		$unarchive = $this->request('DELETE', '/api/v1/posts/' . $post->id . '/archive', [], 'alice');
		self::assertSame(200, $unarchive->status);
		self::assertNull($unarchive->decodedBody()['post']['archived_at']);
		self::assertContains($post->id, $this->ids(
			$this->request('GET', '/api/v1/timelines/public')->decodedBody()['posts'],
		));
	}

	public function testPinsRejectForeignAndNonPublicPostsAndAppearInFeatured(): void
	{
		$post = $this->post('alice', ['a1']);
		$foreign = $this->post('bob');
		$followers = $this->post('alice', [], Visibility::Followers);

		self::assertSame(403, $this->request('PUT', '/api/v1/pins/' . $foreign->id, [], 'alice')->status);
		self::assertSame(422, $this->request('PUT', '/api/v1/pins/' . $followers->id, [], 'alice')->status);

		$pin = $this->request('PUT', '/api/v1/pins/' . $post->id, [], 'alice');
		self::assertSame(200, $pin->status);
		self::assertSame(1, $pin->decodedBody()['pin']['position']);
		self::assertSame(200, $this->request('PUT', '/api/v1/pins/' . $post->id, [], 'alice')->status);
		self::assertCount(1, $this->request('GET', '/api/v1/pins', [], 'alice')->decodedBody()['pins']);

		$status = $this->app->mastodon?->status($post->id, 'alice');
		self::assertNotNull($status);
		self::assertTrue($status['pinned']);

		$actor = $this->request('GET', '/@alice');
		self::assertSame(200, $actor->status);
		self::assertSame('https://social.example/@alice/featured', $actor->decodedBody()['featured']);

		$featured = $this->request('GET', '/@alice/featured');
		self::assertSame(200, $featured->status);
		self::assertSame(1, $featured->decodedBody()['totalItems']);

		$page = $this->request('GET', '/@alice/featured', [], null, ['page' => '1']);
		self::assertSame(200, $page->status);
		self::assertSame(['https://social.example/api/v1/posts/' . $post->id], $page->decodedBody()['orderedItems']);

		self::assertSame(204, $this->request('DELETE', '/api/v1/pins/' . $post->id, [], 'alice')->status);
		self::assertSame(0, $this->request('GET', '/@alice/featured')->decodedBody()['totalItems']);
	}

	public function testCollectionsAreOwnerScopedAndNeverTakeTheirPostsWithThem(): void
	{
		$post = $this->post('alice', ['a1']);

		$create = $this->request('POST', '/api/v1/collections', [
			'title' => 'Favourites',
			'description' => 'Best of',
			'audience' => 'public',
		], 'alice');
		self::assertSame(201, $create->status, $create->body);
		$id = $create->decodedBody()['collection']['id'];

		$add = $this->request('POST', '/api/v1/collections/' . $id . '/items', ['post_id' => $post->id], 'alice');
		self::assertSame(201, $add->status);
		self::assertSame(201, $this->request('POST', '/api/v1/collections/' . $id . '/items', ['post_id' => $post->id], 'alice')->status);

		$actorCollections = $this->request('GET', '/@alice/collections');
		self::assertSame(200, $actorCollections->status);
		self::assertCount(1, $actorCollections->decodedBody()['collections']);
		self::assertSame($id, $actorCollections->decodedBody()['collections'][0]['id']);

		$shown = $this->request('GET', '/api/v1/collections/' . $id);
		self::assertSame(200, $shown->status);
		self::assertSame(1, $shown->decodedBody()['collection']['item_count']);
		self::assertSame($post->id, $shown->decodedBody()['collection']['items'][0]['post']['id']);

		self::assertSame(401, $this->request('POST', '/api/v1/collections', ['title' => 'x'], null)->status);
		self::assertSame(404, $this->request('PATCH', '/api/v1/collections/' . $id, ['title' => 'Stolen'], 'bob')->status);
		self::assertSame(404, $this->request('DELETE', '/api/v1/collections/' . $id, [], 'bob')->status);

		self::assertSame(204, $this->request('DELETE', '/api/v1/collections/' . $id . '/items/' . $post->id, [], 'alice')->status);
		self::assertSame(200, $this->request('GET', '/api/v1/posts/' . $post->id)->status);
		self::assertSame(204, $this->request('DELETE', '/api/v1/collections/' . $id, [], 'alice')->status);
		self::assertSame(404, $this->request('GET', '/api/v1/collections/' . $id)->status);
	}

	/**
	 * @param list<string> $attachmentIds
	 */
	private function post(string $authorId, array $attachmentIds = [], Visibility $visibility = Visibility::Public): Post
	{
		return $this->app->posts->create(new CreatePost(
			authorId: $authorId,
			content: 'A post',
			visibility: $visibility,
			attachmentIds: $attachmentIds,
		));
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function request(string $method, string $path, array $body = [], ?string $userId = null, array $query = []): Response
	{
		return $this->app->handle(new Request(
			method: $method,
			path: $path,
			query: $query,
			headers: $body === [] ? [] : ['Content-Type' => 'application/json'],
			body: $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}

	/**
	 * @param list<array<string, mixed>> $posts
	 *
	 * @return list<string>
	 */
	private function ids(array $posts): array
	{
		return array_map(static fn (array $post): string => $post['id'], $posts);
	}

	private function attachment(string $id): Attachment
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
			createdAt: Time::parse('2026-11-15T11:00:00Z'),
		);
	}
}

