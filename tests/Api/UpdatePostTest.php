<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Post\InMemoryPostRevisionRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * `PATCH /api/v1/posts/{id}` through the real kernel.
 *
 * The shape of the patch (omitted field vs. explicit null), the ownership
 * answer and the optimistic revision are HTTP behaviour, so they are asserted
 * at the boundary the clients actually see.
 */
final class UpdatePostTest extends TestCase
{
	private Application $app;

	private InMemoryPostRevisionRepository $revisions;

	protected function setUp(): void
	{
		$this->revisions = new InMemoryPostRevisionRepository();
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example'],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			postRevisions: $this->revisions,
		);
	}

	public function testTheAuthorEditsTheContentThroughTheApi(): void
	{
		$post = $this->createPost('alice', 'first');

		$response = $this->patch($post['id'], ['content' => '  second  ']);

		self::assertSame(200, $response->status);
		self::assertSame('second', $response->decodedBody()['post']['content']);
		self::assertSame(2, $response->decodedBody()['post']['revision']);
		self::assertNotNull($response->decodedBody()['post']['edited_at']);

		$read = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post['id']));
		self::assertSame('second', $read->decodedBody()['post']['content']);
	}

	public function testThePatchKeepsEveryFieldItDoesNotName(): void
	{
		$post = $this->createPost('alice', 'text', ['visibility' => 'public', 'content_warning' => 'cw']);

		$response = $this->patch($post['id'], ['content' => 'edited']);

		self::assertSame(200, $response->status);
		self::assertSame('cw', $response->decodedBody()['post']['content_warning']);
		self::assertSame('public', $response->decodedBody()['post']['visibility']);
	}

	public function testAnExplicitNullClearsTheContentWarning(): void
	{
		$post = $this->createPost('alice', 'text', ['content_warning' => 'cw']);

		$response = $this->patch($post['id'], ['content_warning' => null]);

		self::assertSame(200, $response->status);
		self::assertNull($response->decodedBody()['post']['content_warning']);
	}

	public function testTheReplacedVersionIsRecorded(): void
	{
		$post = $this->createPost('alice', 'first');

		$this->patch($post['id'], ['content' => 'second']);

		$history = $this->revisions->forPost($post['id']);
		self::assertCount(1, $history);
		self::assertSame('first', $history[0]->content);
		self::assertSame(1, $history[0]->revision);
	}

	public function testSomebodyElseGetsAForbiddenAnswer(): void
	{
		$post = $this->createPost('alice', 'text');

		$response = $this->patch($post['id'], ['content' => 'hijacked'], 'bob');

		self::assertSame(403, $response->status);
		self::assertSame('forbidden', $response->decodedBody()['error']['code']);
	}

	public function testAnUnknownPostIs404(): void
	{
		$response = $this->patch('does-not-exist', ['content' => 'text']);

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
	}

	public function testAnAnonymousWriteIs401(): void
	{
		$post = $this->createPost('alice', 'text');

		$response = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/posts/' . $post['id'],
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'text'], JSON_THROW_ON_ERROR),
		));

		self::assertSame(401, $response->status);
	}

	public function testInvalidContentIs422WithFields(): void
	{
		$post = $this->createPost('alice', 'text');

		$response = $this->patch($post['id'], ['content' => '   ']);

		self::assertSame(422, $response->status);
		self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		self::assertArrayHasKey('content', $response->decodedBody()['error']['fields']);
	}

	public function testAWrongFieldTypeIs422(): void
	{
		$post = $this->createPost('alice', 'text');

		self::assertSame(422, $this->patch($post['id'], ['content' => 42])->status);
		self::assertSame(422, $this->patch($post['id'], ['content_warning' => []])->status);
		self::assertSame(422, $this->patch($post['id'], ['visibility' => 'nowhere'])->status);
		self::assertSame(422, $this->patch($post['id'], ['revision' => 'one'])->status);
		self::assertSame(422, $this->patch($post['id'], ['attachment_ids' => 'a1'])->status);
	}

	public function testAStaleRevisionIs409(): void
	{
		$post = $this->createPost('alice', 'first');
		$this->patch($post['id'], ['content' => 'second']);

		$response = $this->patch($post['id'], ['content' => 'third', 'revision' => 1]);

		self::assertSame(409, $response->status);
		self::assertSame('post_revision_conflict', $response->decodedBody()['error']['code']);
		self::assertStringContainsString('2', $response->decodedBody()['error']['message']);

		$read = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post['id']));
		self::assertSame('second', $read->decodedBody()['post']['content']);
	}

	public function testTheCurrentRevisionIsAccepted(): void
	{
		$post = $this->createPost('alice', 'first');

		$response = $this->patch($post['id'], ['content' => 'second', 'revision' => 1]);

		self::assertSame(200, $response->status);
		self::assertSame(2, $response->decodedBody()['post']['revision']);
	}

	public function testAnEditCannotWidenTheAudience(): void
	{
		$post = $this->createPost('alice', 'text', ['visibility' => 'followers']);

		$response = $this->patch($post['id'], ['visibility' => 'public']);

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('visibility', $response->decodedBody()['error']['fields']);
	}

	public function testAnEditCanNarrowTheAudience(): void
	{
		$post = $this->createPost('alice', 'text', ['visibility' => 'public']);

		$response = $this->patch($post['id'], ['visibility' => 'unlisted']);

		self::assertSame(200, $response->status);
		self::assertSame('unlisted', $response->decodedBody()['post']['visibility']);
	}

	public function testADirectPostKeepsItsAudienceThroughAnEdit(): void
	{
		$post = $this->createPost('alice', 'secret', [
			'visibility' => 'direct',
			'recipient_ids' => ['bob'],
		]);

		$response = $this->patch($post['id'], ['visibility' => 'public']);

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('visibility', $response->decodedBody()['error']['fields']);

		$read = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post['id'], userId: 'alice'));
		self::assertSame('direct', $read->decodedBody()['post']['visibility']);
	}

	public function testADirectPostEditQueuesNoFederationJob(): void
	{
		$post = $this->createPost('alice', 'secret', [
			'visibility' => 'direct',
			'recipient_ids' => ['bob'],
		]);

		$response = $this->patch($post['id'], ['content' => 'still secret']);

		self::assertSame(200, $response->status);
		self::assertSame('still secret', $response->decodedBody()['post']['content']);
		self::assertSame(0, $this->app->queue->pendingCount());
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function createPost(string $authorId, string $content, array $overrides = []): array
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content] + $overrides, JSON_THROW_ON_ERROR),
			userId: $authorId,
		));

		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody()['post'];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function patch(string $postId, array $body, ?string $userId = 'alice'): Response
	{
		return $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/posts/' . $postId,
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}
}
