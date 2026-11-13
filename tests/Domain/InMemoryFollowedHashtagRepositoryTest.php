<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\FollowedHashtagRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryFollowedHashtagRepository;

final class InMemoryFollowedHashtagRepositoryTest extends FollowedHashtagRepositoryTestCase
{
	private FollowedHashtagRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFollowedHashtagRepository();
	}

	protected function repository(): FollowedHashtagRepository
	{
		return $this->repository;
	}
}
