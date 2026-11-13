<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\FollowedHashtagRepository;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagSchema;
use AlohaSocial\Core\Domain\Timeline\PdoFollowedHashtagRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoFollowedHashtagRepositoryTest extends FollowedHashtagRepositoryTestCase
{
	private FollowedHashtagRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		FollowedHashtagSchema::create($connection);

		$this->repository = new PdoFollowedHashtagRepository($connection);
	}

	protected function repository(): FollowedHashtagRepository
	{
		return $this->repository;
	}
}
