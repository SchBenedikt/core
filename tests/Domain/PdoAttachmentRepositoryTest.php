<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Media\PdoAttachmentRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAttachmentRepositoryTest extends AttachmentRepositoryTestCase
{
	private AttachmentRepository $repository;

	private PdoConnection $connection;

	protected function setUp(): void
	{
		$this->connection = new PdoConnection(new PDO('sqlite::memory:'));
		AttachmentSchema::create($this->connection);
		PostSchema::create($this->connection);
		RemotePostSchema::create($this->connection);

		$this->repository = new PdoAttachmentRepository($this->connection);
	}

	protected function repository(): AttachmentRepository
	{
		return $this->repository;
	}

	public function testConditionalDeletePreservesLocalAndRemotePostReferences(): void
	{
		$repository = $this->repository();
		$repository->insert($this->attachment('local-media', 'alice'));
		$repository->insert($this->attachment('remote-media', 'alice'));
		$this->connection->execute(
			'INSERT INTO ' . PostSchema::ATTACHMENT_TABLE . ' (post_id, attachment_id, position)'
			. ' VALUES (:post_id, :attachment_id, 0)',
			['post_id' => 'local-post', 'attachment_id' => 'local-media'],
		);
		$this->connection->execute(
			'INSERT INTO ' . RemotePostSchema::ATTACHMENT_TABLE . ' (activity_id, attachment_id, position)'
			. ' VALUES (:activity_id, :attachment_id, 0)',
			['activity_id' => 'remote-activity', 'attachment_id' => 'remote-media'],
		);

		self::assertFalse($repository->deleteIfUnattached('local-media'));
		self::assertFalse($repository->deleteIfUnattached('remote-media'));
		self::assertNotNull($repository->find('local-media'));
		self::assertNotNull($repository->find('remote-media'));
	}
}
