<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoTimelineException;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoTimelineReader;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoTimelineReaderTest extends TestCase
{
	public function testATimelinePageIsReadWithTheCursorAndTheAuthor(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'cursor' => 'next-page',
			'feed' => [[
				'post' => [
					'uri' => 'at://did:plc:bob/app.bsky.feed.post/abc',
					'cid' => 'bafy-post',
					'author' => [
						'did' => 'did:plc:bob',
						'handle' => 'bob.test',
						'displayName' => 'Bob',
					],
					'text' => 'hello from Bluesky',
					'createdAt' => '2026-11-11T09:00:00.000Z',
					'indexedAt' => '2026-11-11T09:00:01.000Z',
					'replyCount' => 2,
					'repostCount' => 3,
					'likeCount' => 4,
					'embed' => [
						'$type' => 'app.bsky.embed.images#view',
						'images' => [],
					],
				],
			]],
		], JSON_THROW_ON_ERROR)));

		$page = (new XrpcAtprotoTimelineReader($http))->getTimeline($this->account(), 50, 'incoming cursor');

		self::assertSame('next-page', $page->cursor);
		self::assertCount(1, $page->items);
		self::assertSame('did:plc:bob', $page->items[0]->authorDid);
		self::assertSame('Bob', $page->items[0]->authorDisplayName);
		self::assertSame(4, $page->items[0]->likeCount);
		self::assertSame('app.bsky.embed.images#view', $page->items[0]->embed['$type']);
		self::assertSame(
			'https://pds.example/xrpc/app.bsky.feed.getTimeline?limit=50&cursor=incoming%20cursor',
			$http->requests[0]['url'],
		);
		self::assertSame('Bearer token', $http->requests[0]['headers']['Authorization']);
	}

	public function testAnEmptyTimelineHasNoCursor(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"feed":[]}'));

		$page = (new XrpcAtprotoTimelineReader($http))->getTimeline($this->account());

		self::assertSame([], $page->items);
		self::assertNull($page->cursor);
	}

	public function testTheLimitIsClampedToTheProtocolMaximum(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"feed":[]}'));

		(new XrpcAtprotoTimelineReader($http))->getTimeline($this->account(), 5000);

		self::assertStringContainsString('limit=100', $http->requests[0]['url']);
	}

	public function testARefusedRequestIsNotAnEmptyTimeline(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(502, [], '{}'));

		try {
			(new XrpcAtprotoTimelineReader($http))->getTimeline($this->account());
			self::fail('A refused timeline must raise.');
		} catch (AtprotoTimelineException $exception) {
			self::assertSame(AtprotoTimelineException::UNAVAILABLE, $exception->reason);
		}
	}

	public function testAPageWithoutAFeedIsInvalid(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"cursor":"x"}'));

		$this->expectException(AtprotoTimelineException::class);

		try {
			(new XrpcAtprotoTimelineReader($http))->getTimeline($this->account());
		} catch (AtprotoTimelineException $exception) {
			self::assertSame(AtprotoTimelineException::INVALID_RESPONSE, $exception->reason);

			throw $exception;
		}
	}

	private function account(): AtprotoAccount
	{
		return new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token');
	}
}
