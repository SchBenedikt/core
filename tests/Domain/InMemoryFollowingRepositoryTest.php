<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;

final class InMemoryFollowingRepositoryTest extends FollowingRepositoryTestCase
{
	private FollowingRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFollowingRepository();
	}

	protected function repository(): FollowingRepository
	{
		return $this->repository;
	}
}
