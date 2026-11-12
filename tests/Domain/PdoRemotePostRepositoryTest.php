<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
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
}
