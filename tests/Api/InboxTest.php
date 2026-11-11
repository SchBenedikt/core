<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
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

	public function testAReplayedActivityIsRefusedAndChangesNothing(): void
	{
		$follow = $this->follow();

		self::assertSame(202, $this->deliver($follow)->status);
		$this->app->worker->runOnce();

		$replay = $this->deliver($follow);

		self::assertSame(400, $replay->status);
		self::assertSame('replayed_activity', $replay->decodedBody()['error']['code']);
		self::assertSame(1, $this->app->followers->count('alice'));
		self::assertSame(0, $this->app->queue->pendingCount());
		self::assertCount(1, $this->http->requests);
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
			'id' => 'https://remote.example/activities/create-1',
			'type' => 'Create',
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
