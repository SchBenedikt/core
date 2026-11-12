<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class InstanceInfoTest extends TestCase
{
	public function testDescribesConfiguredMetadataAndLocalCounts(): void
	{
		$config = new ArrayConfig([
			InstanceInfo::CONFIG_APP => [
				'instance.name' => 'Example Social',
				'instance.description' => 'A small instance.',
				'instance.contact_email' => 'admin@example.test',
				'instance.contact_account' => 'alice',
				'instance.registrations' => 'yes',
				'instance.approval_required' => 'no',
				'instance.invites_enabled' => '1',
				'instance.languages' => '["de", "en", "de"]',
				'instance.thumbnail' => 'https://example.test/thumbnail.png',
			],
		]);
		$accounts = new InMemoryActorDirectory(['alice', 'bob']);
		$posts = new InMemoryPostRepository();
		$posts->insert($this->post('p1', 'alice'));
		$posts->insert($this->post('p2', 'bob'));
		$posts->softDelete('p2', Time::parse('2026-11-11T10:30:00Z'));

		$remote = new InMemoryRemotePostRepository();
		$remote->insert($this->remote('a1', 'one.example', 'https://one.example/users/carol'));
		$remote->insert($this->remote('a2', 'two.example', 'https://two.example/users/dave'));
		$remote->insert($this->remote('a3', 'one.example', 'https://one.example/users/erin'));
		$remote->softDeleteByActivityId('a3', 'https://one.example/users/erin', Time::parse('2026-11-11T10:30:00Z'));

		$description = (new InstanceInfo($config, $accounts, $posts, $remote))->describe();

		self::assertSame('Example Social', $description['name']);
		self::assertSame('A small instance.', $description['description']);
		self::assertSame('admin@example.test', $description['email']);
		self::assertSame('alice', $description['contact_account']);
		self::assertTrue($description['registrations']);
		self::assertFalse($description['approval_required']);
		self::assertTrue($description['invites_enabled']);
		self::assertSame(['de', 'en'], $description['languages']);
		self::assertSame('https://example.test/thumbnail.png', $description['thumbnail']);
		self::assertSame([
			'user_count' => 2,
			'status_count' => 1,
			'domain_count' => 2,
		], $description['stats']);
	}

	public function testFallsBackToTheShortEmailKeyAndReportsZeroWithoutCountCapabilities(): void
	{
		$info = new InstanceInfo(new ArrayConfig([
			InstanceInfo::CONFIG_APP => ['instance.email' => ' hello@example.test '],
		]));

		self::assertSame('hello@example.test', $info->describe()['email']);
		self::assertSame([
			'user_count' => 0,
			'status_count' => 0,
			'domain_count' => 0,
		], $info->stats());

		$stale = new InstanceInfo(
			new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.contact_account' => 'ghost'],
			]),
			new InMemoryActorDirectory(['alice']),
		);
		self::assertSame('', $stale->describe()['contact_account']);
	}

	private function post(string $id, string $author): Post
	{
		return new Post(
			$id,
			$author,
			'content of ' . $id,
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	private function remote(string $activityId, string $objectAuthor, string $actor): RemotePost
	{
		return new RemotePost(
			$activityId,
			'object-' . $activityId,
			$actor,
			RemotePostKind::Post,
			'remote content',
			null,
			Time::parse('2026-11-11T10:00:00Z'),
			$objectAuthor,
		);
	}
}
