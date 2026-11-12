<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class HttpActorResolverTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const SHARED_INBOX = 'https://remote.example/inbox/shared';

	private static RsaKeyPair $key;

	private FakeHttpClient $http;

	private HttpActorResolver $resolver;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
		$this->resolver = new HttpActorResolver($this->http);
	}

	public function testResolvesTheActorAndItsKey(): void
	{
		$this->http->queueResponse($this->document());

		$actor = $this->resolver->resolveByKeyId(self::KEY_ID);

		self::assertSame(self::ACTOR, $actor->actorId);
		self::assertSame(self::KEY_ID, $actor->keyId);
		self::assertSame(self::INBOX, $actor->inboxUrl);
		self::assertSame(self::SHARED_INBOX, $actor->sharedInboxUrl);
		self::assertSame(self::$key->publicKeyPem(), $actor->publicKeyPem);

		self::assertCount(1, $this->http->requests);
		self::assertSame('GET', $this->http->requests[0]['method']);
		self::assertSame(self::ACTOR, $this->http->requests[0]['url']);
	}

	public function testANonSuccessfulDocumentIsUnknown(): void
	{
		$this->http->queueResponse(new HttpResponse(404));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
	}

	public function testMalformedJsonIsUnknown(): void
	{
		$this->http->queueResponse(new HttpResponse(200, [], '{'));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
	}

	public function testAKeyIdThatTheDocumentDoesNotConfirmIsRefused(): void
	{
		$this->http->queueResponse($this->document([
			'publicKey' => [
				'id' => 'https://remote.example/users/bob#other-key',
				'publicKeyPem' => self::$key->publicKeyPem(),
			],
		]));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
	}

	public function testADocumentForAnotherActorIsRefused(): void
	{
		$this->http->queueResponse($this->document(['id' => 'https://remote.example/users/alice']));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
	}

	public function testAnActorWithoutAnInboxIsRefused(): void
	{
		$document = $this->documentData();
		unset($document['inbox']);
		$this->http->queueResponse(new HttpResponse(200, [], json_encode($document, JSON_THROW_ON_ERROR)));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
	}

	public function testAMalformedSharedInboxFallsBackToTheIndividualInbox(): void
	{
		$this->http->queueResponse($this->document([
			'endpoints' => ['sharedInbox' => 'javascript:alert(1)'],
		]));

		$actor = $this->resolver->resolveByKeyId(self::KEY_ID);

		self::assertNull($actor->sharedInboxUrl);
		self::assertSame(self::INBOX, $actor->inboxUrl);
	}

	public function testANonHttpKeyIdIsRefusedWithoutARequest(): void
	{
		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId('not-a-url#main-key');
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function document(array $overrides = []): HttpResponse
	{
		return new HttpResponse(200, [], json_encode($this->documentData($overrides), JSON_THROW_ON_ERROR));
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function documentData(array $overrides = []): array
	{
		return array_merge([
			'id' => self::ACTOR,
			'type' => 'Person',
			'inbox' => self::INBOX,
			'endpoints' => ['sharedInbox' => self::SHARED_INBOX],
			'publicKey' => [
				'id' => self::KEY_ID,
				'owner' => self::ACTOR,
				'publicKeyPem' => self::$key->publicKeyPem(),
			],
		], $overrides);
	}
}
