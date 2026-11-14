<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\AtprotoProfile;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoBlobUploader;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Service\Atproto\AtprotoProfileRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoProfileService;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoProfileServiceTest extends TestCase
{
	public function testTheProfileUploadsTheAvatarAndWritesTheSelfRecord(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafy-avatar'],
				'mimeType' => 'image/png',
				'size' => 6,
			],
		], JSON_THROW_ON_ERROR)));
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => 'at://did:plc:alice/app.bsky.actor.profile/self',
			'cid' => 'bafy-profile',
		], JSON_THROW_ON_ERROR)));

		$uri = $this->service($http)->publish('alice', new AtprotoProfile(
			'Benedikt',
			'Building Aloha Social.',
			new AtprotoMedia('image/png', 'pngsin', 'Portrait'),
		));

		self::assertSame('at://did:plc:alice/app.bsky.actor.profile/self', $uri);
		self::assertCount(2, $http->requests);
		self::assertSame('image/png', $http->requests[0]['headers']['Content-Type']);
		self::assertSame('pngsin', $http->requests[0]['body']);

		$body = json_decode($http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.actor.profile', $body['collection']);
		self::assertSame('self', $body['rkey']);
		self::assertSame('Benedikt', $body['record']['displayName']);
		self::assertSame('bafy-avatar', $body['record']['avatar']['ref']['$link']);
		self::assertArrayNotHasKey('banner', $body['record']);
	}

	public function testARefusedBannerUploadsNothing(): void
	{
		$http = new FakeHttpClient();

		try {
			$this->service($http)->publish('alice', new AtprotoProfile(
				banner: new AtprotoMedia('image/webp', 'webp-bytes'),
			));
			self::fail('A WebP banner should have been refused.');
		} catch (AtprotoProfileRejectedException $exception) {
			self::assertSame(AtprotoProfileRejectedException::MEDIA_UNSUPPORTED, $exception->reason);
		}

		self::assertCount(0, $http->requests);
	}

	public function testAnOversizedAvatarIsRefusedBeforeAnUpload(): void
	{
		$http = new FakeHttpClient();

		try {
			$this->service($http)->publish('alice', new AtprotoProfile(
				avatar: new AtprotoMedia('image/png', str_repeat('a', 1_000_001)),
			));
			self::fail('An oversized avatar should have been refused.');
		} catch (AtprotoProfileRejectedException $exception) {
			self::assertSame(AtprotoProfileRejectedException::MEDIA_TOO_LARGE, $exception->reason);
		}

		self::assertCount(0, $http->requests);
	}

	public function testTheProfileRecordCanBeRemoved(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{}'));

		$this->service($http)->remove('alice');

		$body = json_decode($http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('self', $body['rkey']);
		self::assertSame('app.bsky.actor.profile', $body['collection']);
	}

	public function testTheFeatureSwitchRefusesAProfileWrite(): void
	{
		$http = new FakeHttpClient();
		$service = new AtprotoProfileService(
			new ArrayConfig(['social' => ['atproto.publish' => 'false']]),
			new InMemoryAtprotoAccountResolver([]),
			new XrpcAtprotoPublisher($http),
			new XrpcAtprotoPublisher($http),
			new XrpcAtprotoBlobUploader($http),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);

		$this->expectException(AtprotoPublishDisabledException::class);

		$service->publish('alice', new AtprotoProfile('Benedikt'));
	}

	private function service(FakeHttpClient $http): AtprotoProfileService
	{
		$publisher = new XrpcAtprotoPublisher($http);

		return new AtprotoProfileService(
			new ArrayConfig(['social' => ['atproto.publish' => 'true']]),
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			$publisher,
			$publisher,
			new XrpcAtprotoBlobUploader($http),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);
	}
}
