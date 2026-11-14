<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Interaction\PdoReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoReactionRepositoryTest extends ReactionRepositoryTestCase
{
	private ReactionRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		ReactionSchema::create($connection);

		$this->repository = new PdoReactionRepository($connection);
	}

	protected function repository(): ReactionRepository
	{
		return $this->repository;
	}
}
