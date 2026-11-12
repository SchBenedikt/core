<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;

final class InMemoryBlockRepositoryTest extends BlockRepositoryTestCase
{
	private BlockRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryBlockRepository();
	}

	protected function repository(): BlockRepository
	{
		return $this->repository;
	}
}
