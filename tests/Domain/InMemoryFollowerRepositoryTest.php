<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;

final class InMemoryFollowerRepositoryTest extends FollowerRepositoryTestCase
{
	private FollowerRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFollowerRepository();
	}

	protected function repository(): FollowerRepository
	{
		return $this->repository;
	}
}
