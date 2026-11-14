<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The public outbox: a collection of the same posts the local API writes,
 * with the collection id the actor document advertises.
 */
final class OutboxTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testTheOutboxIsAnEmptyOrderedCollectionByDefault(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@alice/outbox'));

		self::assertSame(200, $response->status);
		self::assertSame('application/activity+json; charset=utf-8', $response->header('Content-Type'));

		$collection = $response->decodedBody();

		self::assertSame(ActivityFactory::CONTEXT, $collection['@context']);
		self::assertSame(self::ACTOR . '/outbox', $collection['id']);
		self::assertSame('OrderedCollection', $collection['type']);
		self::assertSame(0, $collection['totalItems']);
		self::assertSame(self::ACTOR . '/outbox?page=1', $collection['first']);
		self::assertArrayNotHasKey('orderedItems', $collection);
	}

	public function testTheCollectionIdIsExactlyTheOutboxTheActorDocumentAdvertises(): void
	{
		$app = $this->app();

		$document = $app->handle(new Request('GET', '/@alice'))->decodedBody();
		$collection = $app->handle(new Request('GET', '/@alice/outbox'))->decodedBody();

		self::assertSame($document['outbox'], $collection['id']);

		$route = Routes::manifest()->find('actors.outbox');
		self::assertNotNull($route);
		self::assertSame('/@{handle}/outbox', $route->path);
		self::assertSame(
			'https://localhost:8080' . str_replace('{handle}', 'alice', $route->path),
			$collection['id'],
		);
	}

	public function testAPublishedPostAppearsAsACreateActivity(): void
	{
		$app = $this->app();

		$created = $this->publish($app, 'hello outbox', 'public');
		self::assertSame(201, $created->status);

		$post = $created->decodedBody()['post'];
		$collection = $this->collection($app);

		self::assertSame(1, $collection['totalItems']);
		self::assertCount(1, $collection['orderedItems']);

		$create = $collection['orderedItems'][0];

		self::assertSame('Create', $create['type']);
		self::assertSame(self::ACTOR, $create['actor']);
		self::assertSame('https://localhost:8080/api/v1/posts/' . $post['id'] . '/activity', $create['id']);
		self::assertSame('Note', $create['object']['type']);
		self::assertSame('https://localhost:8080/api/v1/posts/' . $post['id'], $create['object']['id']);
		self::assertSame(self::ACTOR, $create['object']['attributedTo']);
		self::assertSame('hello outbox', $create['object']['content']);
		self::assertSame($post['published_at'], $create['published']);
	}

	public function testAPublicPostPublishesItsAttachmentInTheOutbox(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$attachments->insert(new Attachment(
			id: 'att-1',
			ownerId: 'alice',
			storageKey: 'media/att-1/original.png',
			thumbnailKey: 'media/att-1/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 2048,
			altText: 'outbox alt',
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		));

		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
			attachmentRepository: $attachments,
			blobStore: new InMemoryBlobStore(),
		);

		self::assertSame(201, $this->publish($app, 'with a picture', 'public', ['att-1'])->status);

		$create = $this->collection($app)['orderedItems'][0];

		self::assertSame([[
			'type' => 'Image',
			'url' => 'https://localhost:8080/api/v1/media/att-1',
			'mediaType' => 'image/png',
			'width' => 800,
			'height' => 600,
			'name' => 'outbox alt',
		]], $create['object']['attachment']);
	}

	public function testAFollowersOnlyPostIsNotPublishedInThePublicOutbox(): void
	{
		$app = $this->app();

		self::assertSame(201, $this->publish($app, 'for followers', 'followers')->status);

		$collection = $this->collection($app);

		self::assertSame(0, $collection['totalItems']);
		self::assertSame([], $collection['orderedItems']);
	}

	public function testAnUnknownHandleIsAClean404(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@mallory/outbox'));

		self::assertSame(404, $response->status);
		self::assertSame('actor_not_found', $response->decodedBody()['error']['code']);
		self::assertStringNotContainsString('mallory', (string) json_encode($response->decodedBody()));
	}

	public function testTheOutboxIsDisabledWithoutAKey(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$response = $app->handle(new Request('GET', '/@alice/outbox'));

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	public function testTheOutboxIsDisabledWithoutADirectory(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
		);

		$response = $app->handle(new Request('GET', '/@alice/outbox'));

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collection(Application $app): array
	{
		return $app->handle(new Request('GET', '/@alice/outbox', ['page' => '1']))->decodedBody();
	}

	/**
	 * @param list<string> $attachmentIds
	 */
	private function publish(
		Application $app,
		string $content,
		string $visibility,
		array $attachmentIds = [],
	): Response {
		$body = [
			'content' => $content,
			'visibility' => $visibility,
		];
		if ($attachmentIds !== []) {
			$body['attachment_ids'] = $attachmentIds;
		}

		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}
}
