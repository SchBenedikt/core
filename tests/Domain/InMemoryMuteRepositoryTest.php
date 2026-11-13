<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\MuteRepository;

final class InMemoryMuteRepositoryTest extends MuteRepositoryTestCase
{
	private MuteRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryMuteRepository();
	}

	protected function repository(): MuteRepository
	{
		return $this->repository;
	}
}
