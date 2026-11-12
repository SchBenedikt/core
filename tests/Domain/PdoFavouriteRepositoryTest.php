<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PdoFavouriteRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoFavouriteRepositoryTest extends FavouriteRepositoryTestCase
{
	private FavouriteRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		FavouriteSchema::create($connection);

		$this->repository = new PdoFavouriteRepository($connection);
	}

	protected function repository(): FavouriteRepository
	{
		return $this->repository;
	}
}
