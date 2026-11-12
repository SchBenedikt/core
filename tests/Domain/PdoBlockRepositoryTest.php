<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\PdoBlockRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoBlockRepositoryTest extends BlockRepositoryTestCase
{
	private BlockRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		BlockSchema::create($connection);

		$this->repository = new PdoBlockRepository($connection);
	}

	protected function repository(): BlockRepository
	{
		return $this->repository;
	}
}
