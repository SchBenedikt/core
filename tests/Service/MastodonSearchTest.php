<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Service\Mastodon\MastodonPresenter;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Service\Mastodon\MastodonService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class MastodonSearchTest extends TestCase
{
	public function testStatusSearchWithoutTheOptionalPortIsHonest(): void
	{
		$config = new ArrayConfig([
			InstanceInfo::CONFIG_APP => [
				'mastodon.api' => 'true',
				'instance.url' => 'https://social.example',
			],
		]);
		$posts = new InMemoryPostRepository();
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$activities = new ActivityFactory($config);
		$service = new MastodonService(
			$config,
			$posts,
			new PostService(
				$posts,
				new InMemoryAttachmentRepository(),
				new InMemoryJobQueue($clock, new RandomIdGenerator()),
				new EmptyInboxResolver(),
				$activities,
				$clock,
				new RandomIdGenerator(),
			),
			new MastodonPresenter(new InstanceInfo($config), $activities),
		);

		try {
			$service->search('alice', 'hello', 'statuses', 20, 0, false);
			self::fail('A missing PostSearchRepository must not return an empty page.');
		} catch (MastodonRequestException $exception) {
			self::assertSame(503, $exception->status);
			self::assertSame('search_not_configured', $exception->errorCode);
		}
	}

	public function testHashtagSearchNeedsNoOptionalPorts(): void
	{
		$config = new ArrayConfig([
			MastodonApiConfig::APP => [
				'mastodon.api' => 'true',
			],
		]);
		$posts = new InMemoryPostRepository();
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$activities = new ActivityFactory($config);
		$service = new MastodonService(
			$config,
			$posts,
			new PostService(
				$posts,
				new InMemoryAttachmentRepository(),
				new InMemoryJobQueue($clock, new RandomIdGenerator()),
				new EmptyInboxResolver(),
				$activities,
				$clock,
				new RandomIdGenerator(),
			),
			new MastodonPresenter(new InstanceInfo($config), $activities),
		);

		self::assertSame(
			['accounts' => [], 'statuses' => [], 'hashtags' => []],
			$service->search('alice', 'hello', 'hashtags', 20, 0, false),
		);
	}
}
