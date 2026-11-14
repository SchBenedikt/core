<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;

final class InMemoryRemotePollRepositoryTest extends RemotePollRepositoryTestCase
{
	private RemotePollRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryRemotePollRepository();
	}

	protected function repository(): RemotePollRepository
	{
		return $this->repository;
	}
}
