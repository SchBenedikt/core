<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Media\PdoAttachmentRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAttachmentRepositoryTest extends AttachmentRepositoryTestCase
{
	private AttachmentRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		AttachmentSchema::create($connection);

		$this->repository = new PdoAttachmentRepository($connection);
	}

	protected function repository(): AttachmentRepository
	{
		return $this->repository;
	}
}
