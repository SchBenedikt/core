<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Search\RemoteHandle;
use AlohaSocial\Core\Domain\Search\RemoteSearchException;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\Federation\RemoteFetcher;
use AlohaSocial\Core\Service\Search\WebFingerRemoteActorSearch;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

final class WebFingerRemoteActorSearchTest extends TestCase
{
	private FakeHttpClient $http;

	private WebFingerRemoteActorSearch $search;

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
		$fetcher = new RemoteFetcher(
			$this->http,
			new TestHostResolver(['remote.example' => ['93.184.216.34']]),
		);
		$this->search = new WebFingerRemoteActorSearch(
			$fetcher,
			new FrozenClock(Time::parse('2026-11-14T10:00:00Z')),
		);
	}

	public function testLookupUsesBoundedPinnedRequestsAndDedupesAliases(): void
	{
		$this->http->queueResponse($this->json($this->jrd()));
		$this->http->queueResponse($this->json($this->actor()));

		$profile = $this->search->lookup(RemoteHandle::tryFrom('@bob@remote.example'));

		self::assertNotNull($profile);
		self::assertSame('https://remote.example/users/bob', $profile->actorId);
		self::assertSame('bob@remote.example', $profile->handle);
		self::assertSame('Bob', $profile->displayName);
		self::assertSame('Hello from Bob', $profile->summary);
		self::assertSame('https://remote.example/avatar.png', $profile->avatarUrl);
		self::assertSame('2026-11-14T10:00:00Z', Time::format($profile->fetchedAt));
		self::assertContains('@bob@remote.example', $profile->aliases);
		self::assertContains('https://remote.example/users/robert', $profile->aliases);

		self::assertCount(2, $this->http->requests);
		self::assertSame(
			'https://remote.example/.well-known/webfinger?resource=acct%3Abob%40remote.example',
			$this->http->requests[0]['url'],
		);
		self::assertSame('https://remote.example/users/bob', $this->http->requests[1]['url']);
		self::assertSame(['93.184.216.34'], $this->http->requests[0]['resolved_ips']);
		self::assertSame(['93.184.216.34'], $this->http->requests[1]['resolved_ips']);
		self::assertSame(WebFingerRemoteActorSearch::MAX_JRD_BYTES, $this->http->requests[0]['max_bytes']);
		self::assertSame(WebFingerRemoteActorSearch::MAX_ACTOR_BYTES, $this->http->requests[1]['max_bytes']);
	}

	public function testInvalidHandlesNeverCreateARequest(): void
	{
		foreach (['', 'https://remote.example/@bob', 'bob', '@bob@', '@@remote.example', 'bob@remote example', 'bob@remote..example'] as $value) {
			self::assertNull(RemoteHandle::tryFrom($value), $value);
		}

		self::assertSame([], $this->http->requests);
	}

	public function testPrivateTargetsAreRefusedBeforeAnyRequest(): void
	{
		$search = new WebFingerRemoteActorSearch(new RemoteFetcher(
			$this->http,
			new TestHostResolver(['private.example' => ['10.0.0.5']]),
		));

		try {
			$search->lookup(RemoteHandle::tryFrom('bob@private.example'));
			self::fail('A private target must not be fetched.');
		} catch (RemoteSearchException $exception) {
			self::assertSame('webfinger_ssrf_blocked', $exception->reason);
		}

		self::assertSame([], $this->http->requests);
	}

	public function testAWebFingerWithoutAnActivityPubSelfLinkIsInvalid(): void
	{
		$this->http->queueResponse($this->json([
			'subject' => 'acct:bob@remote.example',
			'links' => [['rel' => 'self', 'type' => 'text/html', 'href' => 'https://remote.example/@bob']],
		]));

		$this->expectException(RemoteSearchException::class);
		$this->expectExceptionMessage('The WebFinger answer has no ActivityPub self link.');

		$this->search->lookup(RemoteHandle::tryFrom('bob@remote.example'));
	}

	public function testAnActorDocumentWithoutAUsableHttpsIdIsRefused(): void
	{
		$this->http->queueResponse($this->json($this->jrd()));
		$this->http->queueResponse($this->json(['id' => 'http://remote.example/users/bob']));

		$this->expectException(RemoteSearchException::class);

		$this->search->lookup(RemoteHandle::tryFrom('bob@remote.example'));
	}

	public function testMalformedJsonAndOversizedAnswersStayBounded(): void
	{
		$this->http->queueResponse(new HttpResponse(200, ['Content-Type' => 'application/jrd+json'], '{'));
		try {
			$this->search->lookup(RemoteHandle::tryFrom('bob@remote.example'));
			self::fail('Malformed JSON must be refused.');
		} catch (RemoteSearchException $exception) {
			self::assertSame('webfinger_invalid', $exception->reason);
		}

		$this->http->queueResponse($this->json($this->jrd()));
		$this->http->queueResponse(new HttpResponse(
			200,
			['Content-Type' => 'application/activity+json'],
			str_repeat('x', WebFingerRemoteActorSearch::MAX_ACTOR_BYTES + 1),
		));
		try {
			$this->search->lookup(RemoteHandle::tryFrom('bob@remote.example'));
			self::fail('An oversized actor document must be refused.');
		} catch (RemoteSearchException $exception) {
			self::assertSame('actor_too_large', $exception->reason);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function jrd(): array
	{
		return [
			'subject' => 'acct:bob@remote.example',
			'links' => [[
				'rel' => 'self',
				'type' => 'application/activity+json',
				'href' => 'https://remote.example/users/bob',
			]],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function actor(): array
	{
		return [
			'id' => 'https://remote.example/users/bob',
			'type' => 'Person',
			'preferredUsername' => 'bob',
			'name' => 'Bob',
			'summary' => 'Hello from Bob',
			'icon' => ['type' => 'Image', 'url' => 'https://remote.example/avatar.png'],
			'image' => ['type' => 'Image', 'url' => 'https://remote.example/header.png'],
			'alsoKnownAs' => ['https://remote.example/users/robert'],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function json(array $data): HttpResponse
	{
		return new HttpResponse(
			200,
			['Content-Type' => 'application/activity+json'],
			json_encode($data, JSON_THROW_ON_ERROR),
		);
	}
}
