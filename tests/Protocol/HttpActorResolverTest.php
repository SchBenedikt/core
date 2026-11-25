<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Protocol\ActivityPub\GuardedActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
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
		$this->resolver = new HttpActorResolver(
			$this->http,
			new FederationTargetGuard(new TestHostResolver([
				'remote.example' => ['93.184.216.34'],
			])),
		);
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

	public function testResolvesACustomFollowersCollection(): void
	{
		$this->http->queueResponse($this->document(['followers' => 'https://remote.example/collections/followers']));

		self::assertSame(
			'https://remote.example/collections/followers',
			$this->resolver->resolveByKeyId(self::KEY_ID)->followersUrl,
		);
	}

	public function testTheDocumentAliasesAndMovedToAreValidated(): void
	{
		$this->http->queueResponse($this->document([
			'alsoKnownAs' => [
				'https://new.example/users/bob',
				'javascript:alert(1)',
				'https://new.example/users/bob',
			],
			'movedTo' => 'https://new.example/users/bob',
		]));

		$actor = $this->resolver->resolveByKeyId(self::KEY_ID);

		self::assertSame(['https://new.example/users/bob'], $actor->alsoKnownAs);
		self::assertSame('https://new.example/users/bob', $actor->movedTo);
	}

	public function testASingleAliasStringIsAccepted(): void
	{
		$this->http->queueResponse($this->document([
			'alsoKnownAs' => 'https://new.example/users/bob',
		]));

		$actor = $this->resolver->resolveByKeyId(self::KEY_ID);

		self::assertSame(['https://new.example/users/bob'], $actor->alsoKnownAs);
	}

	public function testATargetActorIsFetchedByUrlAndMustIdentifyItself(): void
	{
		$this->http->queueResponse($this->document(['alsoKnownAs' => [self::ACTOR]]));

		$actor = $this->resolver->fetch(self::ACTOR);

		self::assertSame(self::ACTOR, $actor->actorId);
		self::assertSame(self::KEY_ID, $actor->keyId);
		self::assertSame([self::ACTOR], $actor->alsoKnownAs);
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

	public function testAPrivateActorTargetIsRefusedWithoutARequest(): void
	{
		$resolver = new HttpActorResolver(
			$this->http,
			new FederationTargetGuard(new TestHostResolver([
				'remote.example' => ['10.0.0.5'],
			])),
		);

		$this->expectException(UnknownActorException::class);

		try {
			$resolver->resolveByKeyId(self::KEY_ID);
		} finally {
			self::assertSame([], $this->http->requests);
		}
	}

	public function testTheDocumentFetchIsBoundedAndPinnedToTheValidatedAddress(): void
	{
		$this->http->queueResponse($this->document());

		$this->resolver->resolveByKeyId(self::KEY_ID);

		self::assertCount(1, $this->http->requests);
		self::assertSame(['93.184.216.34'], $this->http->requests[0]['resolved_ips']);
		self::assertSame(HttpActorResolver::TIMEOUT_SECONDS, $this->http->requests[0]['timeout']);
		self::assertSame(HttpActorResolver::MAX_DOCUMENT_BYTES, $this->http->requests[0]['max_bytes']);
	}

	public function testAGuardWithoutAResolverCannotVouchForAHostname(): void
	{
		$resolver = new HttpActorResolver($this->http, new FederationTargetGuard());

		$this->expectException(UnknownActorException::class);

		try {
			$resolver->resolveByKeyId(self::KEY_ID);
		} finally {
			self::assertSame([], $this->http->requests);
		}
	}

	public function testWithoutItsOwnGuardTheResolverFetchesNothing(): void
	{
		// A resolver used as the entry point has no validated address to pin
		// the connection to, so it refuses instead of falling back to an
		// unchecked request.
		$resolver = new HttpActorResolver($this->http);

		$this->expectException(UnknownActorException::class);

		try {
			$resolver->resolveByKeyId(self::KEY_ID);
		} finally {
			self::assertSame([], $this->http->requests);
		}
	}

	public function testTheValidatedAddressesAreNotResolvedASecondTime(): void
	{
		$hostResolver = new class implements HostResolver {
			public int $calls = 0;

			public function resolve(string $host): array
			{
				++$this->calls;

				// A rebinding peer answers with a public address first and a
				// private one for the connection.
				return $this->calls === 1 ? ['93.184.216.34'] : ['10.0.0.5'];
			}
		};

		$guard = new FederationTargetGuard($hostResolver);
		$resolver = new GuardedActorResolver(new HttpActorResolver($this->http), $guard);

		$this->http->queueResponse($this->document());

		$actor = $resolver->resolveByKeyId(self::KEY_ID);

		self::assertSame(1, $hostResolver->calls);
		self::assertCount(1, $this->http->requests);
		self::assertSame(['93.184.216.34'], $this->http->requests[0]['resolved_ips']);
		self::assertSame(self::INBOX, $actor->inboxUrl);
	}

	public function testAnOversizedActorDocumentIsUnknown(): void
	{
		$this->http->queueResponse(new HttpResponse(
			200,
			[],
			str_repeat('x', HttpActorResolver::MAX_DOCUMENT_BYTES + 1),
		));

		$this->expectException(UnknownActorException::class);

		$this->resolver->resolveByKeyId(self::KEY_ID);
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
