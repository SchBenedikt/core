<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\AccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryAccountMoveRepository;

final class InMemoryAccountMoveRepositoryTest extends AccountMoveRepositoryTestCase
{
	private AccountMoveRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryAccountMoveRepository();
	}

	protected function repository(): AccountMoveRepository
	{
		return $this->repository;
	}
}
