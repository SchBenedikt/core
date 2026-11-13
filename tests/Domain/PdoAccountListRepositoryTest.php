<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\AccountListRepository;
use AlohaSocial\Core\Domain\Timeline\AccountListSchema;
use AlohaSocial\Core\Domain\Timeline\PdoAccountListRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoAccountListRepositoryTest extends AccountListRepositoryTestCase
{
	private AccountListRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		AccountListSchema::create($connection);

		$this->repository = new PdoAccountListRepository($connection);
	}

	protected function repository(): AccountListRepository
	{
		return $this->repository;
	}
}
