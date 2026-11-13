<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorInboxResolver;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

final class HttpActorInboxResolverTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	public function testResolvesTheIndividualInbox(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->document());

		$inbox = (new HttpActorInboxResolver($http))->inboxFor(self::ACTOR);

		self::assertSame(self::INBOX, $inbox);
		self::assertSame('GET', $http->requests[0]['method']);
		self::assertSame(self::ACTOR, $http->requests[0]['url']);
	}

	public function testANonHttpActorIsRefusedWithoutARequest(): void
	{
		$http = new FakeHttpClient();

		self::assertNull((new HttpActorInboxResolver($http))->inboxFor('bob'));
		self::assertSame([], $http->requests);
	}

	public function testAPrivateTargetHasNoInboxAndMakesNoRequest(): void
	{
		$http = new FakeHttpClient();
		$resolver = new HttpActorInboxResolver(
			$http,
			new FederationTargetGuard(new TestHostResolver([
				'remote.example' => ['127.0.0.1'],
			])),
		);

		self::assertNull($resolver->inboxFor(self::ACTOR));
		self::assertSame([], $http->requests);
	}

	public function testAnUnavailableOrMismatchingDocumentHasNoInbox(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(404));
		$http->queueResponse(new HttpResponse(200, [], '{'));
		$http->queueResponse($this->document(['id' => 'https://remote.example/users/alice']));
		$http->queueResponse($this->document(['inbox' => 'javascript:alert(1)']));

		$resolver = new HttpActorInboxResolver($http);

		self::assertNull($resolver->inboxFor(self::ACTOR));
		self::assertNull($resolver->inboxFor(self::ACTOR));
		self::assertNull($resolver->inboxFor(self::ACTOR));
		self::assertNull($resolver->inboxFor(self::ACTOR));
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function document(array $overrides = []): HttpResponse
	{
		return new HttpResponse(200, [], json_encode(array_merge([
			'id' => self::ACTOR,
			'type' => 'Person',
			'inbox' => self::INBOX,
		], $overrides), JSON_THROW_ON_ERROR));
	}
}
