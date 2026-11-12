<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\PdoRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThreadSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoRemoteThreadRepositoryTest extends RemoteThreadRepositoryTestCase
{
	private RemoteThreadRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RemoteThreadSchema::create($connection);

		$this->repository = new PdoRemoteThreadRepository($connection);
	}

	protected function repository(): RemoteThreadRepository
	{
		return $this->repository;
	}
}
