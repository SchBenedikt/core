<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoFollowingRepositoryTest extends FollowingRepositoryTestCase
{
	private FollowingRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		FollowingSchema::create($connection);

		$this->repository = new PdoFollowingRepository($connection);
	}

	protected function repository(): FollowingRepository
	{
		return $this->repository;
	}
}
