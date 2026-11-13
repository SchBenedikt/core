<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\PdoBoostRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoBoostRepositoryTest extends BoostRepositoryTestCase
{
	private BoostRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		BoostSchema::create($connection);

		$this->repository = new PdoBoostRepository($connection);
	}

	protected function repository(): BoostRepository
	{
		return $this->repository;
	}
}
