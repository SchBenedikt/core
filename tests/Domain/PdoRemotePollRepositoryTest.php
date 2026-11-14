<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\PdoRemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoRemotePollRepositoryTest extends RemotePollRepositoryTestCase
{
	private RemotePollRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RemotePollSchema::create($connection);

		$this->repository = new PdoRemotePollRepository($connection);
	}

	protected function repository(): RemotePollRepository
	{
		return $this->repository;
	}
}
