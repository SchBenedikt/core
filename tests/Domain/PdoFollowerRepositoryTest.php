<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\PdoFollowerRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoFollowerRepositoryTest extends FollowerRepositoryTestCase
{
	private FollowerRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		FollowerSchema::create($connection);

		$this->repository = new PdoFollowerRepository($connection);
	}

	protected function repository(): FollowerRepository
	{
		return $this->repository;
	}
}
