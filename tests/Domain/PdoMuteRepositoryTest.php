<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\PdoMuteRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use PDO;

final class PdoMuteRepositoryTest extends MuteRepositoryTestCase
{
	private MuteRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		MuteSchema::create($connection);

		$this->repository = new PdoMuteRepository($connection);
	}

	protected function repository(): MuteRepository
	{
		return $this->repository;
	}
}
