<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The inbound half of federation: one signed activity in, one state change
 * out. Every refusal is asserted with its status and its stable code.
 */
final class InboxTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const LOCAL_ACTOR = 'https://localhost:8080/@alice';

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
		$this->app = $this->app();
	}

	public function testAnUnsignedRequestIsRefused(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			body: json_encode($this->follow(), JSON_THROW_ON_ERROR),
		));

		self::assertSame(401, $response->status);
		self::assertSame('signature_required', $response->decodedBody()['error']['code']);
	}

	public function testInboundBlockRemovesFollowEdgesAndUndoRestoresOnlyItsActor(): void
	{
		self::assertSame(202, $this->deliver($this->follow())->status);
		$block = [
			'id' => 'https://remote.example/activities/block-1',
			'type' => 'Block',
			'actor' => self::ACTOR,
			'object' => self::LOCAL_ACTOR,
		];
		self::assertSame(202, $this->deliver($block)->status);
		self::assertSame(202, $this->deliver($block)->status, 'An exact Block redelivery is an idempotent success.');
		self::assertSame(0, $this->app->followers->count('alice'));
		self::assertSame('blocked_actor', $this->deliver($this->follow('https://remote.example/activities/follow-after-block'))->decodedBody()['error']['code']);

		$undo = [
			'id' => 'https://remote.example/activities/undo-block-1',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $block,
		];
		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(202, $this->deliver($this->follow('https://remote.example/activities/follow-after-undo'))->status);
	}

	public function testUndoBlockUriOnlyMustMatchTheStoredBlockActivity(): void
	{
		$block = [
			'id' => 'https://remote.example/activities/block-uri-only',
			'type' => 'Block',
			'actor' => self::ACTOR,
			'object' => self::LOCAL_ACTOR,
		];
		self::assertSame(202, $this->deliver($block)->status);

		$wrongUndo = [
			'id' => 'https://remote.example/activities/undo-wrong-block',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => 'https://remote.example/activities/other-block',
		];
		self::assertSame(202, $this->deliver($wrongUndo)->status);
		self::assertSame('blocked_actor', $this->deliver($this->follow('https://remote.example/activities/follow-after-wrong-undo'))->decodedBody()['error']['code']);

		$undo = $wrongUndo;
		$undo['id'] = 'https://remote.example/activities/undo-correct-block';
		$undo['object'] = $block['id'];
		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(202, $this->deliver($this->follow('https://remote.example/activities/follow-after-correct-undo'))->status);
		$undo['id'] = 'https://remote.example/activities/undo-correct-block-retry';
		self::assertSame(202, $this->deliver($undo)->status);
	}

	public function testInboundBlockIsIdempotentAndRejectsAForgedUndo(): void
	{
		$block = [
			'id' => 'https://remote.example/activities/block-idempotent',
			'type' => 'Block',
			'actor' => self::ACTOR,
			'object' => ['id' => self::LOCAL_ACTOR, 'type' => 'Person'],
		];
		self::assertSame(202, $this->deliver($block)->status);
		$duplicate = $block;
		$duplicate['id'] = 'https://remote.example/activities/block-idempotent-retry';
		self::assertSame(202, $this->deliver($duplicate)->status);

		$forgedUndo = [
			'id' => 'https://remote.example/activities/forged-undo',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => [
				'type' => 'Block',
				'actor' => 'https://attacker.example/users/forged',
				'object' => self::LOCAL_ACTOR,
			],
		];
		$response = $this->deliver($forgedUndo);
		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame('blocked_actor', $this->deliver($this->follow('https://remote.example/activities/follow-still-blocked'))->decodedBody()['error']['code']);
	}

	public function testEmbeddedUndoCannotRetargetAnotherLocalActor(): void
	{
		$block = [
			'id' => 'https://remote.example/activities/block-alice-only',
			'type' => 'Block',
			'actor' => self::ACTOR,
			'object' => self::LOCAL_ACTOR,
		];
		self::assertSame(202, $this->deliver($block)->status);
		$undo = [
			'id' => 'https://remote.example/activities/undo-retargeted-block',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => [
				'id' => $block['id'],
				'type' => 'Block',
				'actor' => self::ACTOR,
				'object' => 'https://localhost:8080/@mallory',
			],
		];
		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame('blocked_actor', $this->deliver($this->follow('https://remote.example/activities/follow-alice-still-blocked'))->decodedBody()['error']['code']);
	}

	public function testBlockRequiresAnActorObjectOwnedByThisInstance(): void
	{
		$base = [
			'id' => 'https://remote.example/activities/block-invalid-target',
			'type' => 'Block',
			'actor' => self::ACTOR,
		];
		$invalidNested = $base + ['object' => ['id' => self::LOCAL_ACTOR, 'type' => 'Note']];
		$response = $this->deliver($invalidNested);
		self::assertSame(400, $response->status);
		self::assertSame('invalid_activity', $response->decodedBody()['error']['code']);

		$unknownLocal = $base + ['object' => 'https://localhost:8080/@ghost'];
		$response = $this->deliver($unknownLocal);
		self::assertSame(404, $response->status);
		self::assertSame('unknown_object', $response->decodedBody()['error']['code']);
	}

	public function testTheSharedInboxAlsoRequiresASignature(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox/shared',
			body: json_encode($this->follow(), JSON_THROW_ON_ERROR),
		));

		self::assertSame(401, $response->status);
		self::assertSame('signature_required', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->app->followers->count('alice'));
	}

	public function testAnUnknownKeyIsRefusedWithoutRevealingWhy(): void
	{
		$response = $this->deliver($this->follow(), keyId: 'https://unknown.example/users/x#main-key');

		self::assertSame(404, $response->status);
		self::assertSame('unknown_actor', $response->decodedBody()['error']['code']);
		self::assertStringNotContainsString('unknown.example', (string) json_encode($response->decodedBody()));
	}

	public function testATamperedBodyIsRefused(): void
	{
		$signedBody = json_encode($this->follow(), JSON_THROW_ON_ERROR);
		$tampered = json_encode(
			$this->follow(object: 'https://localhost:8080/@mallory'),
			JSON_THROW_ON_ERROR,
		);

		$response = $this->deliver($this->follow(), body: $signedBody, sendBody: $tampered);

		self::assertSame(401, $response->status);
		self::assertSame('invalid_signature', $response->decodedBody()['error']['code']);
	}

	public function testAnExpiredSignatureIsRefused(): void
	{
		$response = $this->deliver(
			$this->follow(),
			at: $this->clock->now()->modify('-1000 seconds'),
		);

		self::assertSame(401, $response->status);
		self::assertSame('signature_expired', $response->decodedBody()['error']['code']);
	}

	public function testARemoteFollowCreatesOneNotificationForTheLocalAccount(): void
	{
		self::assertSame(202, $this->deliver($this->follow())->status);
		self::assertSame(202, $this->deliver($this->follow('https://remote.example/activities/follow-2'))->status);

		$response = $this->app->handle(new Request(
			method: 'GET',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/notifications',
			userId: 'alice',
			scopes: ['read:notifications'],
		));

		self::assertSame(200, $response->status);
		self::assertCount(1, $response->decodedBody());
		self::assertSame('follow', $response->decodedBody()[0]['type']);
		self::assertSame(self::ACTOR, $response->decodedBody()[0]['account']['id']);
		self::assertSame('bob@remote.example', $response->decodedBody()[0]['account']['acct']);
		self::assertArrayNotHasKey('status', $response->decodedBody()[0]);
	}

	public function testAFollowCreatesTheEdgeAndQueuesAnAccept(): void
	{
		$follow = $this->follow();

		$response = $this->deliver($follow);

		self::assertSame(202, $response->status);
		self::assertSame('accepted', $response->decodedBody()['status']);
		self::assertSame(1, $this->app->followers->count('alice'));
		self::assertSame(1, $this->app->queue->pendingCount());

		self::assertSame(1, $this->app->worker->runOnce());
		self::assertSame(0, $this->app->queue->pendingCount());
		self::assertCount(1, $this->http->requests);

		$request = $this->http->requests[0];
		self::assertSame(self::INBOX, $request['url']);

		$accept = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Accept', $accept['type']);
		self::assertSame(self::LOCAL_ACTOR, $accept['actor']);
		self::assertSame($follow['id'], $accept['object']['id']);
		self::assertSame(self::ACTOR, $accept['to'][0]);

		HttpSignature::verify(
			self::$localKey->publicKeyPem(),
			'POST',
			'/users/bob/inbox',
			array_change_key_case($request['headers'], CASE_LOWER),
			$request['body'],
			300,
			$this->clock->now(),
		);

		self::assertTrue(true);
	}

	public function testTheSharedInboxStoresTheSharedInboxAndAcceptsOnTheIndividualOne(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::KEY_ID => new RemoteActor(
					self::ACTOR,
					self::KEY_ID,
					self::INBOX,
					self::$remoteKey->publicKeyPem(),
					'https://remote.example/inbox/shared',
				),
			]),
			activityLog: new InMemoryActivityLog(),
		);

		$follow = $this->follow();
		$body = json_encode($follow, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::KEY_ID,
			'POST',
			'/inbox/shared',
			'localhost',
			$body,
			$this->clock->now(),
		);

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/inbox/shared',
			headers: $headers,
			body: $body,
		));

		self::assertSame(202, $response->status);
		$follower = $app->followers->followers('alice')[0];
		self::assertSame('https://remote.example/inbox/shared', $follower->sharedInboxUrl);

		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(self::INBOX, $this->http->requests[0]['url']);
	}

	public function testAnIdenticalRetryIsAcceptedWithoutSideEffects(): void
	{
		$follow = $this->follow();

		self::assertSame(202, $this->deliver($follow)->status);
		$this->app->worker->runOnce();

		$retry = $this->deliver($follow);

		self::assertSame(202, $retry->status);
		self::assertSame('accepted', $retry->decodedBody()['status']);
		self::assertSame(1, $this->app->followers->count('alice'));
		self::assertSame(0, $this->app->queue->pendingCount());
		self::assertCount(1, $this->http->requests);
	}

	public function testAConflictingReuseOfAClaimedActivityIdIsRefused(): void
	{
		$follow = $this->follow();

		self::assertSame(202, $this->deliver($follow)->status);

		$conflict = $this->follow(object: 'https://localhost:8080/@mallory');
		$conflict['id'] = $follow['id'];

		$response = $this->deliver($conflict);

		self::assertSame(400, $response->status);
		self::assertSame('replayed_activity', $response->decodedBody()['error']['code']);
		self::assertSame(1, $this->app->followers->count('alice'));
	}

	public function testASecondFollowWithANewIdIsIdempotent(): void
	{
		self::assertSame(202, $this->deliver($this->follow('https://remote.example/activities/follow-1'))->status);
		self::assertSame(202, $this->deliver($this->follow('https://remote.example/activities/follow-2'))->status);

		self::assertSame(1, $this->app->followers->count('alice'));
	}

	public function testUndoFollowRemovesTheEdge(): void
	{
		self::assertSame(202, $this->deliver($this->follow())->status);
		self::assertSame(1, $this->app->followers->count('alice'));

		$undo = $this->undo();

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(0, $this->app->followers->count('alice'));
	}

	public function testUndoForAnUnknownFollowIsStillAccepted(): void
	{
		self::assertSame(202, $this->deliver($this->undo())->status);
		self::assertSame(0, $this->app->followers->count('alice'));
	}

	public function testAnUnsupportedActivityIsRefused(): void
	{
		$response = $this->deliver([
			'id' => 'https://remote.example/activities/dislike-1',
			'type' => 'Dislike',
			'actor' => self::ACTOR,
		]);

		self::assertSame(400, $response->status);
		self::assertSame('unsupported_activity', $response->decodedBody()['error']['code']);
	}

	public function testAnActivityFromAnotherActorIsRefused(): void
	{
		$response = $this->deliver([
			'id' => 'https://remote.example/activities/follow-9',
			'type' => 'Follow',
			'actor' => 'https://mallory.example/users/eve',
			'object' => self::LOCAL_ACTOR,
		]);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
	}

	public function testAForeignObjectIsRefused(): void
	{
		$response = $this->deliver($this->follow(object: 'https://elsewhere.example/@alice'));

		self::assertSame(404, $response->status);
		self::assertSame('unknown_object', $response->decodedBody()['error']['code']);
	}

	public function testAnActivityWithoutAnIdIsRefused(): void
	{
		$response = $this->deliver([
			'type' => 'Follow',
			'actor' => self::ACTOR,
			'object' => self::LOCAL_ACTOR,
		]);

		self::assertSame(400, $response->status);
		self::assertSame('invalid_activity', $response->decodedBody()['error']['code']);
	}

	public function testBrokenJsonIsRefused(): void
	{
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::KEY_ID,
			'POST',
			'/inbox',
			'localhost',
			'{',
			$this->clock->now(),
		);

		$response = $this->app->handle(new Request(method: 'POST', path: '/inbox', headers: $headers, body: '{'));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_json', $response->decodedBody()['error']['code']);
	}

	public function testAnUndoOfSomebodyElsesFollowIsRefused(): void
	{
		$undo = $this->undo();
		$undo['object']['actor'] = 'https://mallory.example/users/eve';

		$response = $this->deliver($undo);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->app->followers->count('alice'));
	}

	public function testTheInboxIsHonestlyDisabledWithoutAnActorResolver(): void
	{
		$app = Application::inMemory(clock: $this->clock);

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			body: json_encode($this->follow(), JSON_THROW_ON_ERROR),
		));

		self::assertSame(503, $response->status);
		self::assertSame('inbox_not_configured', $response->decodedBody()['error']['code']);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'mallory']),
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

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(
		array $activity,
		?string $body = null,
		?string $sendBody = null,
		?string $keyId = null,
		?DateTimeImmutable $at = null,
	): Response {
		$body ??= json_encode($activity, JSON_THROW_ON_ERROR);

		$headers = HttpSignature::sign(
			self::$remoteKey,
			$keyId ?? self::KEY_ID,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$at ?? $this->clock->now(),
		);

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $sendBody ?? $body,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function follow(
		string $id = 'https://remote.example/activities/follow-1',
		string $object = self::LOCAL_ACTOR,
	): array {
		return [
			'id' => $id,
			'type' => 'Follow',
			'actor' => self::ACTOR,
			'object' => $object,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function undo(): array
	{
		return [
			'id' => 'https://remote.example/activities/undo-1',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $this->follow('https://remote.example/activities/follow-undone'),
		];
	}
}
