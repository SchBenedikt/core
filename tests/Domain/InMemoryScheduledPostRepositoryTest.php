<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Scheduling\InMemoryScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostRepository;

final class InMemoryScheduledPostRepositoryTest extends ScheduledPostRepositoryTestCase
{
	private ScheduledPostRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryScheduledPostRepository();
	}

	protected function repository(): ScheduledPostRepository
	{
		return $this->repository;
	}
}
