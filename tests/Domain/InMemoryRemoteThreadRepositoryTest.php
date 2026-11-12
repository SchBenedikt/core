<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;

final class InMemoryRemoteThreadRepositoryTest extends RemoteThreadRepositoryTestCase
{
	private RemoteThreadRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryRemoteThreadRepository();
	}

	protected function repository(): RemoteThreadRepository
	{
		return $this->repository;
	}
}
