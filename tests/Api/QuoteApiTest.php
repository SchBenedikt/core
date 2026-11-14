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
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\QuoteService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Quote posts across the neutral API, FEP-044f and the Mastodon projection.
 */
final class QuoteApiTest extends TestCase
{
	private const BASE = 'https://social.example';

	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const OBJECT = 'https://remote.example/notes/1';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private InMemoryRemotePostRepository $remotePosts;

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
		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => self::BASE,
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
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
			remotePostRepository: $this->remotePosts,
		);
	}

	public function testALocalQuoteIsAcceptedSynchronouslyAndPublishesAnAuthorization(): void
	{
		$target = $this->post('alice', 'quoted words')->decodedBody()['post'];
		$quote = $this->post('bob', 'look at this', quotedPostId: $target['id']);

		self::assertSame(201, $quote->status);
		$body = $quote->decodedBody()['post'];
		self::assertSame('accepted', $body['quote_state']);
		self::assertSame(self::BASE . '/api/v1/posts/' . $target['id'], $body['quoted_object_id']);
		self::assertNotNull($body['quote_authorization_id']);

		$document = $this->app->handle(new Request(
			'GET',
			'/api/v1/quote-authorizations/' . $this->grantId($target['id'], $body['id']),
		));
		self::assertSame(200, $document->status);
		self::assertSame('QuoteAuthorization', $document->decodedBody()['type']);
		self::assertSame(self::BASE . '/api/v1/posts/' . $target['id'], $document->decodedBody()['interactionTarget']);
		self::assertSame(self::BASE . '/api/v1/posts/' . $body['id'], $document->decodedBody()['interactingObject']);
		self::assertSame(self::BASE . '/@alice', $document->decodedBody()['attributedTo']);

		$status = $this->app->handle(new Request(
			'GET',
			'/api/mastodon/v1/statuses/' . $body['id'],
			userId: 'bob',
			scopes: ['read:statuses'],
		));
		self::assertSame(200, $status->status);
		self::assertSame('accepted', $status->decodedBody()['quote']['state']);
		self::assertSame($target['id'], $status->decodedBody()['quote']['quoted_status']['id']);
	}

	public function testOnlyALivePublicOrUnlistedPostCanBeQuoted(): void
	{
		$private = $this->post('alice', 'followers only', 'followers')->decodedBody()['post'];
		self::assertSame(422, $this->post('bob', 'nope', quotedPostId: $private['id'])->status);

		$direct = $this->post('alice', 'just for bob', 'direct', ['bob'])->decodedBody()['post'];
		self::assertSame(422, $this->post('bob', 'nope', quotedPostId: $direct['id'])->status);

		self::assertSame(422, $this->post('bob', 'nope', quotedPostId: 'missing')->status);

		$unlisted = $this->post('alice', 'quiet', 'unlisted')->decodedBody()['post'];
		self::assertSame(201, $this->post('bob', 'yes', quotedPostId: $unlisted['id'])->status);
	}

	public function testTheQuotedAuthorCanRevokeAndThePreviewDisappears(): void
	{
		$target = $this->post('alice', 'quoted words')->decodedBody()['post'];
		$quote = $this->post('bob', 'look at this', quotedPostId: $target['id'])->decodedBody()['post'];
		$grantId = $this->grantId($target['id'], $quote['id']);

		$denied = $this->app->handle(new Request(
			'DELETE',
			'/api/v1/quote-authorizations/' . $grantId,
			userId: 'bob',
		));
		self::assertSame(403, $denied->status);

		$revoked = $this->app->handle(new Request(
			'DELETE',
			'/api/v1/quote-authorizations/' . $grantId,
			userId: 'alice',
		));
		self::assertSame(200, $revoked->status);
		self::assertSame('revoked', $revoked->decodedBody()['state']);

		self::assertSame(404, $this->app->handle(new Request(
			'GET',
			'/api/v1/quote-authorizations/' . $grantId,
		))->status);

		$status = $this->app->handle(new Request(
			'GET',
			'/api/mastodon/v1/statuses/' . $quote['id'],
			userId: 'bob',
			scopes: ['read:statuses'],
		));
		self::assertSame('revoked', $status->decodedBody()['quote']['state']);
		self::assertNull($status->decodedBody()['quote']['quoted_status']);
	}

	public function testARemoteQuoteStartsPendingAndSendsAQuoteRequest(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/1',
			objectId: self::OBJECT,
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'remote words',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			objectAuthorId: self::ACTOR,
		));

		$this->http->queueResponse(new HttpResponse(200, ['content-type' => 'application/activity+json'], json_encode([
			'id' => self::ACTOR,
			'inbox' => self::INBOX,
		], JSON_THROW_ON_ERROR)));

		$quote = $this->post('alice', 'remote quote', quotedPostId: self::OBJECT);

		self::assertSame(201, $quote->status);
		$body = $quote->decodedBody()['post'];
		self::assertSame('pending', $body['quote_state']);
		self::assertNull($body['quote_authorization_id']);
		self::assertSame(self::OBJECT, $body['quoted_object_id']);

		$jobs = $this->app->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('QuoteRequest', $jobs[0]->payload['activity']['type']);
		self::assertSame(self::OBJECT, $jobs[0]->payload['activity']['object']);
		self::assertSame(self::BASE . '/api/v1/posts/' . $body['id'], $jobs[0]->payload['activity']['instrument']);
		self::assertSame(self::INBOX, $jobs[0]->payload['inbox']);
	}

	public function testAnAcceptFromTheQuotedAuthorApprovesAPendingQuote(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/1',
			objectId: self::OBJECT,
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'remote words',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			objectAuthorId: self::ACTOR,
		));
		$this->http->queueResponse(new HttpResponse(200, [], json_encode(
			['id' => self::ACTOR, 'inbox' => self::INBOX],
			JSON_THROW_ON_ERROR,
		)));
		$quote = $this->post('alice', 'remote quote', quotedPostId: self::OBJECT)->decodedBody()['post'];
		$request = [
			'id' => self::BASE . '/api/v1/posts/' . $quote['id'] . '/quote-request',
			'type' => 'QuoteRequest',
			'actor' => self::BASE . '/@alice',
			'object' => self::OBJECT,
			'instrument' => self::BASE . '/api/v1/posts/' . $quote['id'],
		];

		$accept = $this->deliver([
			'id' => 'https://remote.example/activities/accept-1',
			'type' => 'Accept',
			'actor' => self::ACTOR,
			'object' => $request,
			'result' => ['id' => 'https://remote.example/quotes/auth-1', 'type' => 'QuoteAuthorization'],
		]);
		self::assertSame(202, $accept->status);

		$read = $this->app->handle(new Request('GET', '/api/v1/posts/' . $quote['id'], userId: 'alice'));
		self::assertSame('accepted', $read->decodedBody()['post']['quote_state']);
		self::assertSame('https://remote.example/quotes/auth-1', $read->decodedBody()['post']['quote_authorization_id']);
	}

	public function testASignedQuoteRequestForAPublicPostIsAcceptedAndDereferenceable(): void
	{
		$target = $this->post('alice', 'quote me')->decodedBody()['post'];
		$instrument = 'https://remote.example/notes/quote-1';

		$response = $this->deliver([
			'id' => 'https://remote.example/activities/quote-request-1',
			'type' => 'QuoteRequest',
			'actor' => self::ACTOR,
			'object' => self::BASE . '/api/v1/posts/' . $target['id'],
			'instrument' => $instrument,
		]);

		self::assertSame(202, $response->status);

		$grantId = QuoteService::grantId(self::BASE . '/api/v1/posts/' . $target['id'], $instrument);
		$document = $this->app->handle(new Request('GET', '/api/v1/quote-authorizations/' . $grantId));
		self::assertSame(200, $document->status);
		self::assertSame(self::BASE . '/@alice', $document->decodedBody()['attributedTo']);

		$jobs = $this->app->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('Accept', $jobs[0]->payload['activity']['type']);
		self::assertSame($grantId, $this->grantIdFromUrl($jobs[0]->payload['activity']['result']['id']));
		self::assertSame(self::INBOX, $jobs[0]->payload['inbox']);
	}

	public function testASignedQuoteRequestForAPrivatePostIsRejected(): void
	{
		$target = $this->post('alice', 'private', 'followers')->decodedBody()['post'];
		$instrument = 'https://remote.example/notes/quote-private';

		$response = $this->deliver([
			'id' => 'https://remote.example/activities/quote-request-2',
			'type' => 'QuoteRequest',
			'actor' => self::ACTOR,
			'object' => self::BASE . '/api/v1/posts/' . $target['id'],
			'instrument' => $instrument,
		]);

		self::assertSame(202, $response->status);
		$jobs = $this->app->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('Reject', $jobs[0]->payload['activity']['type']);

		$grantId = QuoteService::grantId(self::BASE . '/api/v1/posts/' . $target['id'], $instrument);
		self::assertSame(404, $this->app->handle(new Request(
			'GET',
			'/api/v1/quote-authorizations/' . $grantId,
		))->status);
	}

	public function testAnUnknownQuoteTargetIsRefusedAsAnActivity(): void
	{
		$response = $this->deliver([
			'id' => 'https://remote.example/activities/quote-request-3',
			'type' => 'QuoteRequest',
			'actor' => self::ACTOR,
			'object' => 'https://elsewhere.example/notes/1',
			'instrument' => 'https://remote.example/notes/quote-3',
		]);

		self::assertSame(400, $response->status);
		self::assertSame('quote_not_available', $response->decodedBody()['error']['code']);
	}

	public function testABlockedRemoteAuthorCannotBeQuoted(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/1',
			objectId: self::OBJECT,
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'remote words',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			objectAuthorId: self::ACTOR,
		));

		$block = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::ACTOR], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $block->status);

		self::assertSame(422, $this->post('alice', 'blocked quote', quotedPostId: self::OBJECT)->status);
	}

	private function grantId(string $targetId, string $quoteId): string
	{
		return QuoteService::grantId(
			self::BASE . '/api/v1/posts/' . $targetId,
			self::BASE . '/api/v1/posts/' . $quoteId,
		);
	}

	private function grantIdFromUrl(string $url): ?string
	{
		$prefix = self::BASE . '/api/v1/quote-authorizations/';

		return str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : null;
	}

	private function post(
		string $authorId,
		string $content,
		string $visibility = 'public',
		array $recipientIds = [],
		?string $quotedPostId = null,
	): Response {
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => $content,
				'visibility' => $visibility,
				'recipient_ids' => $recipientIds,
				'quoted_post_id' => $quotedPostId,
			], JSON_THROW_ON_ERROR),
			userId: $authorId,
		));
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
		);

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}
}
