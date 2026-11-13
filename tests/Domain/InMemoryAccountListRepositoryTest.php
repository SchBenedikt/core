<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\AccountListRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryAccountListRepository;

final class InMemoryAccountListRepositoryTest extends AccountListRepositoryTestCase
{
	private AccountListRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryAccountListRepository();
	}

	protected function repository(): AccountListRepository
	{
		return $this->repository;
	}
}
