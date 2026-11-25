<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;

/**
 * Remote Question import and voting through the real kernel.
 */
final class RemotePollApiTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const QUESTION = 'https://remote.example/questions/1';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private Application $app;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->http = new FakeHttpClient();
		$this->app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
			]]),
			localActors: new InMemoryActorDirectory(['alice']),
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::KEY_ID => new RemoteActor(
					self::ACTOR,
					self::KEY_ID,
					self::INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
		);
	}

	public function testAQuestionBecomesAReadablePollAndAcceptsAVote(): void
	{
		$this->followAccepted();
		self::assertSame(202, $this->deliver($this->question())->status);

		$path = '/api/v1/remote-polls/' . rawurlencode(self::QUESTION);
		$read = $this->app->handle(new Request('GET', $path, userId: 'alice'));
		self::assertSame(200, $read->status, (string) json_encode($read->decodedBody()));
		self::assertSame('Lunch?', $read->decodedBody()['poll']['title']);
		self::assertSame(2, count($read->decodedBody()['poll']['options']));
		self::assertFalse($read->decodedBody()['poll']['voted']);

		$this->http->queueResponse(new HttpResponse(200, ['content-type' => 'application/activity+json'], json_encode([
			'id' => self::ACTOR,
			'inbox' => self::INBOX,
		], JSON_THROW_ON_ERROR)));

		$vote = $this->app->handle(new Request(
			method: 'POST',
			path: $path . '/votes',
			body: json_encode(['choices' => [0]], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(200, $vote->status, (string) json_encode($vote->decodedBody()));
		self::assertTrue($vote->decodedBody()['poll']['voted']);
		self::assertSame([0], $vote->decodedBody()['poll']['own_votes']);
		self::assertSame('queued', $vote->decodedBody()['poll']['delivery']);
		self::assertSame(4, $vote->decodedBody()['poll']['options'][0]['votes_count']);
	}

	public function testASignedVoteNoteUpdatesALocalPoll(): void
	{
		$post = $this->app->posts->create(new CreatePost(
			authorId: 'alice',
			content: 'Vote please',
			poll: new Poll(
				options: [new PollOption('Yes'), new PollOption('No')],
				multiple: false,
				hideTotals: false,
				expiresAt: Time::parse('2026-12-01T12:00:00Z'),
			),
		));

		self::assertSame(202, $this->deliver($this->voteNote($post->id, 'Yes'))->status);

		$detail = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post->id, userId: 'alice'));
		self::assertSame(1, $detail->decodedBody()['post']['poll']['options'][0]['votes_count']);
		self::assertSame(1, $detail->decodedBody()['post']['poll']['votes_count']);
	}

	public function testAVoteForAnUnknownOptionIsRefused(): void
	{
		$post = $this->app->posts->create(new CreatePost(
			authorId: 'alice',
			content: 'Vote please',
			poll: new Poll(
				options: [new PollOption('Yes'), new PollOption('No')],
				multiple: false,
				hideTotals: false,
				expiresAt: Time::parse('2026-12-01T12:00:00Z'),
			),
		));

		$response = $this->deliver($this->voteNote($post->id, 'Maybe'));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_choice', $response->decodedBody()['error']['code']);
	}

	private function followAccepted(): void
	{
		$result = $this->app->following->follow('alice', self::ACTOR, self::INBOX);

		self::assertSame(202, $this->deliver([
			'id' => 'https://remote.example/activities/accept-1',
			'type' => 'Accept',
			'actor' => self::ACTOR,
			'object' => [
				'id' => $result->followed->followActivityId,
				'type' => 'Follow',
				'actor' => 'https://localhost:8080/@alice',
				'object' => self::ACTOR,
			],
		])->status);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function question(): array
	{
		return [
			'id' => 'https://remote.example/activities/question-1',
			'type' => 'Create',
			'actor' => self::ACTOR,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => [
				'id' => self::QUESTION,
				'type' => 'Question',
				'name' => 'Lunch?',
				'attributedTo' => self::ACTOR,
				'oneOf' => [
					['type' => 'Note', 'name' => 'Pizza', 'replies' => ['type' => 'Collection', 'totalItems' => 3]],
					['type' => 'Note', 'name' => 'Sushi', 'replies' => ['type' => 'Collection', 'totalItems' => 1]],
				],
				'endTime' => '2026-12-01T12:00:00Z',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function voteNote(string $postId, string $choice): array
	{
		return [
			'id' => 'https://remote.example/activities/vote-1',
			'type' => 'Create',
			'actor' => self::ACTOR,
			'object' => [
				'id' => 'https://remote.example/notes/vote-1',
				'type' => 'Note',
				'attributedTo' => self::ACTOR,
				'inReplyTo' => 'https://localhost:8080/api/v1/posts/' . $postId,
				'name' => $choice,
			],
		];
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(array $activity, ?DateTimeImmutable $at = null): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::KEY_ID,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$at ?? $this->clock->now(),
			$at ?? $this->clock->now(),
		);

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}
}
