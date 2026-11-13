<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryBoostRepository;

final class InMemoryBoostRepositoryTest extends BoostRepositoryTestCase
{
	private BoostRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryBoostRepository();
	}

	protected function repository(): BoostRepository
	{
		return $this->repository;
	}
}
