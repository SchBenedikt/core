<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\AccountMoveState;
use AlohaSocial\Core\Domain\Federation\InMemoryAccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryRemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PHPUnit\Framework\TestCase;

final class AccountMoveTest extends TestCase
{
	private const SOURCE = 'https://old.example/users/alice';

	private const SOURCE_KEY = 'https://old.example/users/alice#main-key';

	private const SOURCE_INBOX = 'https://old.example/users/alice/inbox';

	private const TARGET = 'https://new.example/users/alice';

	private const TARGET_KEY = 'https://new.example/users/alice#main-key';

	private const TARGET_INBOX = 'https://new.example/users/alice/inbox';

	private static RsaKeyPair $localKey;

	private static RsaKeyPair $remoteKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private InMemoryAccountMoveRepository $moveRepository;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
		self::$remoteKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
		$this->http = new FakeHttpClient();
		$this->moveRepository = new InMemoryAccountMoveRepository();
	}

	public function testAReciprocatedMoveMigratesAcceptedAndPendingFollowsInBatches(): void
	{
		$app = $this->app();
		$users = ['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8', 'u9', 'u10',
			'u11', 'u12', 'u13', 'u14', 'u15', 'u16', 'u17', 'u18', 'u19', 'u20', 'u21'];

		foreach ($users as $index => $user) {
			$result = $app->following->follow($user, self::SOURCE, self::SOURCE_INBOX);
			if ($index > 0) {
				$app->following->respondToFollow($result->followed->followActivityId ?? '', self::SOURCE, \AlohaSocial\Core\Domain\Federation\FollowState::Accepted);
			}
		}

		$activity = $this->moveActivity('move-21');
		$response = $this->deliver($app, $activity);
		self::assertSame(202, $response->status);
		$eventId = $activity['id'];
		self::assertNotNull($app->accountMoves?->find($eventId));

		$first = $app->accountMoves?->continue($eventId);
		self::assertSame(AccountMoveState::Pending, $first?->state);
		self::assertCount(20, $first?->processedOutgoingIds ?? []);
		self::assertCount(20, $this->targetFollowers($app, $users));
		self::assertCount(1, $this->sourceFollowers($app, $users));

		$second = $app->accountMoves?->continue($eventId);
		self::assertTrue($second?->isCompleted());
		self::assertCount(21, $second?->processedOutgoingIds ?? []);
		self::assertCount(21, $this->targetFollowers($app, $users));
		self::assertSame([], $this->sourceFollowers($app, $users));
		self::assertSame('pending', $app->following->find('u1', self::TARGET)?->state->value);
		self::assertSame('accepted', $app->following->find('u2', self::TARGET)?->state->value);
	}

	public function testAcceptedFollowersMoveAndPendingRequestsAreDropped(): void
	{
		$app = $this->app();
		$app->followers->follow(new FollowActor('u1', self::SOURCE, self::SOURCE_INBOX));
		$app->followers->follow(
			new FollowActor('u2', self::SOURCE, self::SOURCE_INBOX),
			requiresApproval: true,
			followActivity: ['id' => 'https://old.example/follow/pending', 'type' => 'Follow'],
		);

		$activity = $this->moveActivity('move-followers');
		self::assertSame(202, $this->deliver($app, $activity)->status);
		$eventId = $activity['id'];
		$app->accountMoves?->continue($eventId);

		$moved = $app->followers->followers('u1', 10);
		self::assertCount(1, $moved);
		self::assertSame(self::TARGET, $moved[0]->actorId);
		self::assertSame('accepted', $moved[0]->state->value);
		self::assertSame(self::TARGET_INBOX, $moved[0]->inboxUrl);
		self::assertSame([], $app->followers->followers('u2', 10));
		self::assertSame([], $app->followers->pending('u2'));
	}

	public function testAnUnreciprocatedMoveIsRejectedWithoutChangingTheGraph(): void
	{
		$app = $this->app(null, ['https://other.example/users/alice'], ['u1']);
		$app->following->follow('u1', self::SOURCE, self::SOURCE_INBOX);

		$activity = $this->moveActivity('move-spoofed');
		$response = $this->deliver($app, $activity);

		self::assertSame(403, $response->status);
		self::assertSame('move_not_reciprocated', $response->decodedBody()['error']['code']);
		self::assertNull($this->moveRepository->find($activity['id']));
		self::assertNotNull($app->following->find('u1', self::SOURCE));
	}

	public function testAMoveForAForeignObjectIsRejected(): void
	{
		$app = $this->app();
		$activity = $this->moveActivity('move-foreign');
		$activity['object'] = 'https://old.example/users/mallory';

		$response = $this->deliver($app, $activity);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertNull($this->moveRepository->find($activity['id']));
	}

	public function testAMoveToAnUnknownTargetIsRejected(): void
	{
		$app = $this->app([], null, ['u1', 'u2'], false);
		$activity = $this->moveActivity('move-unknown');

		$response = $this->deliver($app, $activity);

		self::assertSame(404, $response->status);
		self::assertSame('move_target_unknown', $response->decodedBody()['error']['code']);
		self::assertNull($this->moveRepository->find($activity['id']));
	}

	public function testAnOutgoingMoveKeepsTheActorAndKeyStableAndPublishesMove(): void
	{
		$app = $this->app(['https://localhost:8080/@alice'], [self::TARGET], ['alice']);
		$app->followers->follow(new FollowActor(
			'alice',
			'https://remote.example/users/follower',
			'https://remote.example/users/follower/inbox',
		));
		$before = $app->handle(new Request('GET', '/@alice'))->decodedBody();
		$move = $app->accountMoves?->moveLocalAccount('alice', self::TARGET);
		self::assertNotNull($move);
		self::assertSame(AccountMoveState::Completed, $move->state);

		$after = $app->handle(new Request('GET', '/@alice'))->decodedBody();
		self::assertSame($before['id'], $after['id']);
		self::assertSame($before['publicKey']['id'], $after['publicKey']['id']);
		self::assertSame(self::TARGET, $after['movedTo']);
		self::assertSame([self::TARGET], $after['alsoKnownAs']);

		$again = $app->accountMoves?->moveLocalAccount('alice', self::TARGET);
		self::assertSame($move->eventId, $again?->eventId);
		self::assertSame(2, $app->queue->pendingCount(), 'A same-target retry requeues the stable Move to every follower inbox.');
		$app->queue->cancelBySubject(JobSubject::account('alice'));
		self::assertSame(0, $app->queue->pendingCount());
		$app->accountMoves?->moveLocalAccount('alice', self::TARGET);
		self::assertSame(1, $app->queue->pendingCount(), 'A retry restores deliveries cancelled by a failed request path.');

		$app->worker->runOnce();
		self::assertCount(1, $this->http->requests);
		$activity = json_decode($this->http->requests[0]['body'], true, 8, JSON_THROW_ON_ERROR);
		self::assertSame('Move', $activity['type']);
		self::assertSame('https://localhost:8080/@alice', $activity['object']);
		self::assertSame(self::TARGET, $activity['target']);
	}

	public function testOwnerCanStartAndInspectMoveThroughAuthenticatedApiAndCannotPublishAfterward(): void
	{
		$app = $this->app(
			['https://localhost:8080/@alice'],
			[self::TARGET],
			['alice'],
		);
		$body = json_encode(['target_actor_id' => self::TARGET], JSON_THROW_ON_ERROR);
		$started = $app->handle(new Request(
			'POST',
			'/api/v1/accounts/me/move',
			headers: ['Content-Type' => 'application/json'],
			body: $body,
			userId: 'alice',
		));

		self::assertSame(202, $started->status, $started->body);
		$summary = $started->decodedBody()['move'];
		self::assertSame(self::TARGET, $summary['target_actor_id']);
		self::assertArrayNotHasKey('target_inbox_url', $summary);
		self::assertArrayNotHasKey('processed_follower_ids', $summary);

		$status = $app->handle(new Request('GET', '/api/v1/accounts/me/move', userId: 'alice'));
		self::assertSame(200, $status->status);
		self::assertSame(self::TARGET, $status->decodedBody()['move']['target_actor_id']);

		$publish = $app->handle(new Request(
			'POST',
			'/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'after move'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(422, $publish->status, $publish->body);
		self::assertArrayHasKey('account', $publish->decodedBody()['error']['fields']);
	}

	public function testMoveApiRequiresAuthentication(): void
	{
		$app = $this->app(['https://localhost:8080/@alice'], [self::TARGET], ['alice']);
		$response = $app->handle(new Request('GET', '/api/v1/accounts/me/move'));
		self::assertSame(401, $response->status);
	}

	/**
	 * @param list<string>|null $targetAliases
	 * @param list<string>|null $aliases
	 * @param list<string>      $localUsers
	 */
	private function app(
		?array $targetAliases = null,
		?array $aliases = null,
		array $localUsers = ['u1', 'u2'],
		bool $targetAvailable = true,
	): Application {
		$targetAliases ??= [self::SOURCE];
		$target = new RemoteActor(
			self::TARGET,
			self::TARGET_KEY,
			self::TARGET_INBOX,
			self::$remoteKey->publicKeyPem(),
			null,
			$targetAliases,
			null,
		);
		$source = new RemoteActor(
			self::SOURCE,
			self::SOURCE_KEY,
			self::SOURCE_INBOX,
			self::$remoteKey->publicKeyPem(),
			null,
			$aliases ?? [self::TARGET],
			null,
		);

		return Application::inMemory(
			clock: $this->clock,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([self::SOURCE_KEY => $source]),
			localActors: new InMemoryActorDirectory($localUsers),
			accountMoveRepository: $this->moveRepository,
			remoteActors: new InMemoryRemoteActorFetcher($targetAvailable ? [
				self::TARGET => $target,
			] : []),
		);
	}

	/**
	 * @param list<string> $users
	 *
	 * @return list<string>
	 */
	private function targetFollowers(Application $app, array $users): array
	{
		return array_values(array_filter(
			$users,
			static fn (string $user): bool => $app->following->find($user, self::TARGET) !== null,
		));
	}

	/**
	 * @param list<string> $users
	 *
	 * @return list<string>
	 */
	private function sourceFollowers(Application $app, array $users): array
	{
		return array_values(array_filter(
			$users,
			static fn (string $user): bool => $app->following->find($user, self::SOURCE) !== null,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function moveActivity(string $id): array
	{
		return [
			'id' => 'https://old.example/activities/' . $id,
			'type' => 'Move',
			'actor' => self::SOURCE,
			'object' => self::SOURCE,
			'target' => self::TARGET,
		];
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::SOURCE_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $app->handle(new Request('POST', '/inbox', headers: $headers, body: $body));
	}
}
