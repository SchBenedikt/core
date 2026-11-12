<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;

final class InMemoryFavouriteRepositoryTest extends FavouriteRepositoryTestCase
{
	private FavouriteRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFavouriteRepository();
	}

	protected function repository(): FavouriteRepository
	{
		return $this->repository;
	}
}
