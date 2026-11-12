<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoPostRepositoryTest extends PostRepositoryTestCase
{
	private PostRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($connection);
		PollSchema::create($connection);

		$this->repository = new PdoPostRepository($connection);
	}

	protected function repository(): PostRepository
	{
		return $this->repository;
	}

	protected function searchRepository(): PostSearchRepository
	{
		return $this->repository;
	}
}
