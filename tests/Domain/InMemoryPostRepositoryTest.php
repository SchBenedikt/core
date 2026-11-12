<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;

final class InMemoryPostRepositoryTest extends PostRepositoryTestCase
{
	private ?InMemoryPostRepository $repository = null;

	protected function repository(): PostRepository
	{
		return $this->repository ??= new InMemoryPostRepository();
	}

	protected function searchRepository(): PostSearchRepository
	{
		return $this->repository();
	}
}
