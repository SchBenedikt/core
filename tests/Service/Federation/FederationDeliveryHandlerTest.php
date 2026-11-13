<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Federation;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

final class FederationDeliveryHandlerTest extends TestCase
{
	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const KEY_ID = 'https://social.example/@alice#main-key';

	private FakeHttpClient $http;

	private FederationDeliveryHandler $handler;

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
		$this->handler = new FederationDeliveryHandler(
			$this->http,
			RsaKeyPair::generate(),
			new FrozenClock(Time::parse('2026-11-13T12:00:00Z')),
			new FederationTargetGuard(new TestHostResolver([
				'remote.example' => ['93.184.216.34'],
				'private.example' => ['10.0.0.5'],
			])),
		);
	}

	public function testAPublicTargetIsSignedAndSent(): void
	{
		$this->handler->deliver(self::INBOX, ['type' => 'Follow'], self::KEY_ID);

		self::assertCount(1, $this->http->requests);
		self::assertSame('POST', $this->http->requests[0]['method']);
		self::assertSame(self::INBOX, $this->http->requests[0]['url']);
		self::assertArrayHasKey('Signature', $this->http->requests[0]['headers']);
	}

	public function testAPrivateTargetIsRefusedBeforeTheRequest(): void
	{
		try {
			$this->handler->deliver('https://private.example/inbox', ['type' => 'Follow'], self::KEY_ID);
			self::fail('A private target must not be delivered.');
		} catch (RemoteFetchException $exception) {
			self::assertSame('ssrf_blocked', $exception->reason);
		}

		self::assertSame([], $this->http->requests);
	}

	public function testANonSuccessfulAnswerFailsTheJob(): void
	{
		$this->http->queueResponse(new HttpResponse(500));

		$this->expectException(DeliveryFailedException::class);
		$this->handler->deliver(self::INBOX, ['type' => 'Follow'], self::KEY_ID);
	}
}
