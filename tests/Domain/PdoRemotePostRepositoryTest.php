<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteObjectMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;

final class PdoRemotePostRepositoryTest extends RemotePostRepositoryTestCase
{
	private RemotePostRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RemotePostSchema::create($connection);

		$this->repository = new PdoRemotePostRepository($connection);
	}

	protected function repository(): RemotePostRepository
	{
		return $this->repository;
	}

	public function testTheUpgradeAddsTheTypedColumnsToAnExistingTable(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		$connection->execute(
			'CREATE TABLE social_remote_post ('
			. ' activity_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' object_id VARCHAR(512) NOT NULL,'
			. ' object_author_id VARCHAR(255) NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' kind VARCHAR(16) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' deleted_at VARCHAR(20) NULL'
			. ')',
		);

		foreach (RemotePostSchema::upgradeStatements() as $statement) {
			$connection->execute($statement);
		}

		$repository = new PdoRemotePostRepository($connection);
		$repository->insert(new RemotePost(
			activityId: 'activity-1',
			objectId: 'https://peer.example/articles/1',
			actorId: 'https://peer.example/users/bob',
			kind: RemotePostKind::Post,
			content: '',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T10:00:00Z'),
			objectType: RemoteObjectType::Article,
			metadata: new RemoteObjectMetadata(title: 'Upgraded'),
		));

		$found = $repository->findByActivityId('activity-1');

		self::assertNotNull($found);
		self::assertSame(RemoteObjectType::Article, $found->objectType);
		self::assertSame('Upgraded', $found->metadata?->title);
	}
}
